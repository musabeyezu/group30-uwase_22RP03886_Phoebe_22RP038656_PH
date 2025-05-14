<?php
// ussd.php
error_reporting(E_ALL);
ini_set('display_errors', 1);
require_once 'db.php';
require_once 'sms.php';

// Handle missing POST keys safely
$sessionId   = isset($_POST["sessionId"]) ? $_POST["sessionId"] : "";
$serviceCode = isset($_POST["serviceCode"]) ? $_POST["serviceCode"] : "";
$phoneNumber = isset($_POST["phoneNumber"]) ? $_POST["phoneNumber"] : "";
$text        = isset($_POST["text"]) ? $_POST["text"] : "";

// Stop execution if required keys are missing (for manual testing protection)
if (!$sessionId || !$phoneNumber) {
    echo "END Invalid access.";
    exit;
}

// Temp session file
$session_file = sys_get_temp_dir() . "/ussd_" . md5($sessionId);

function save_session($session_file, $data) {
    file_put_contents($session_file, json_encode($data));
}
function load_session($session_file) {
    if (file_exists($session_file)) {
        return json_decode(file_get_contents($session_file), true);
    }
    return ["step" => 0];
}
function clear_session($session_file) {
    if (file_exists($session_file)) unlink($session_file);
}

$input = explode("*", $text);
$last_input = end($input);

// Load or initialize session
$session = load_session($session_file);
$step = $session["step"] ?? 0;
$response = "";

// Navigation: Go back (9), Main menu (0)
if ($last_input === "9") {
    $step = max(0, $step - 1);
    array_pop($input);
    $session["step"] = $step;
    save_session($session_file, $session);
    $text = implode("*", $input);
    $input = explode("*", $text);
    $last_input = end($input);
}
if ($last_input === "0") {
    $step = 0;
    $session = ["step" => 0];
    save_session($session_file, $session);
    $input = [];
}

switch ($step) {
    case 0:
        $response = "CON Welcome to the umurimo Cooperative Workshops.\nAre you a maize farmer?\n1. Yes\n2. No";
        $session["step"] = 1;
        save_session($session_file, $session);
        break;

    case 1:
        if ($input[0] == "1") {
            $workshops = [];
            $result = $conn->query("SELECT id, name FROM workshops");
            while ($row = $result->fetch_assoc()) {
                $workshops[] = $row;
            }
            $session["workshops"] = $workshops;
            $menu = "CON Book your seat from:";
            foreach ($workshops as $i => $w) {
                $menu .= "\n" . ($i+1) . ". " . $w["name"];
            }
            $menu .= "\n9. Go back\n0. Main menu";
            $response = $menu;
            $session["step"] = 2;
            save_session($session_file, $session);
        } else if ($input[0] == "2") {
            $response = "END Sorry, only maize farmers can register for these workshops.";
            clear_session($session_file);
        } else {
            $response = "END Invalid input. Please try again.";
            clear_session($session_file);
        }
        break;

    case 2:
        $workshops = $session["workshops"] ?? [];
        $choice = intval($input[1] ?? 0) - 1;
        if (isset($workshops[$choice])) {
            $workshop_id = $workshops[$choice]["id"];
            $session["selected_workshop_id"] = $workshop_id;
            $stmt = $conn->prepare("SELECT * FROM workshops WHERE id=?");
            $stmt->bind_param("i", $workshop_id);
            $stmt->execute();
            $details = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            $booked = $details["booked_seats"];
            $capacity = $details["capacity"];
            $menu = "CON Venue: {$details['venue']}\nDate: {$details['date']} {$details['time']}\nSeats: $booked/$capacity\n1. Register\n9. Go back\n0. Main menu";
            $response = $menu;
            $session["step"] = 3;
            save_session($session_file, $session);
        } else {
            $response = "END Invalid workshop selection.";
            clear_session($session_file);
        }
        break;

    case 3:
        if (($input[2] ?? '') == "1") {
            $response = "CON Enter your full name:\n9. Go back\n0. Main menu";
            $session["step"] = 4;
            save_session($session_file, $session);
        } else {
            $response = "END Registration cancelled.";
            clear_session($session_file);
        }
        break;

    case 4:
        $fullname = $last_input;
        if ($fullname === "9" || $fullname === "0") break;
        $session["fullname"] = $fullname;
        $response = "CON Enter your ID number:\n9. Go back\n0. Main menu";
        $session["step"] = 5;
        save_session($session_file, $session);
        break;

    case 5:
        $id_number = $last_input;
        if ($id_number === "9" || $id_number === "0") break;
        $session["id_number"] = $id_number;
        $response = "CON Enter your residence (district/sector):\n9. Go back\n0. Main menu";
        $session["step"] = 6;
        save_session($session_file, $session);
        break;

    case 6:
        $residence = $last_input;
        if ($residence === "9" || $residence === "0") break;
        $session["residence"] = $residence;

        $id_number = $session["id_number"];
        $workshop_id = $session["selected_workshop_id"];
        $fullname = $session["fullname"];

        // Check or insert farmer
        $stmt = $conn->prepare("SELECT id FROM farmers WHERE id_number=?");
        $stmt->bind_param("s", $id_number);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($row = $result->fetch_assoc()) {
            $farmer_id = $row["id"];
        } else {
            $stmt2 = $conn->prepare("INSERT INTO farmers (name, phone, id_number, residence) VALUES (?, ?, ?, ?)");
            $stmt2->bind_param("ssss", $fullname, $phoneNumber, $id_number, $residence);
            $stmt2->execute();
            $farmer_id = $stmt2->insert_id;
            $stmt2->close();
        }
        $stmt->close();

        // Check for duplicate registration
        $stmt = $conn->prepare("SELECT id FROM registrations WHERE farmer_id=? AND workshop_id=?");
        $stmt->bind_param("ii", $farmer_id, $workshop_id);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($result->fetch_assoc()) {
            $response = "END You have already registered for this workshop.";
            clear_session($session_file);
            break;
        }

        // Check workshop capacity
        $stmt = $conn->prepare("SELECT booked_seats, capacity FROM workshops WHERE id=?");
        $stmt->bind_param("i", $workshop_id);
        $stmt->execute();
        $workshop = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($workshop["booked_seats"] >= $workshop["capacity"]) {
            $response = "END Sorry, this workshop is fully booked.";
            clear_session($session_file);
            break;
        }

        // Register farmer
        $stmt = $conn->prepare("INSERT INTO registrations (farmer_id, workshop_id) VALUES (?, ?)");
        $stmt->bind_param("ii", $farmer_id, $workshop_id);
        $stmt->execute();
        $stmt->close();

        // Update booked seats
        $stmt = $conn->prepare("UPDATE workshops SET booked_seats = booked_seats + 1 WHERE id=?");
        $stmt->bind_param("i", $workshop_id);
        $stmt->execute();
        $stmt->close();

        $response = "END Registration successful! Thank you.";
        clear_session($session_file);
        break;

    default:
        $response = "END Invalid input. Please try again.";
        clear_session($session_file);
        break;
}

header('Content-type: text/plain');
echo $response;
$sms = new Sms($_POST["phoneNumber"]);
$smsResponse = $sms->sendSMS($response,$_POST["phoneNumber"]);

if($smsResponse['status']=='Success' || $smsResponse['status']=='success'){
    echo 'End you will receive a short messaged';
}
else{
    echo 'Opps msg failed to be delivered';
}

?>
