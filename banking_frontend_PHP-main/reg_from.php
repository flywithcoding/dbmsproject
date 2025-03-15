<?php
// db_config.php: Database Connection
$servername = "localhost";
$username = "root";
$password = "passdimuna#19";
$dbname = "banking_project_db";

$conn = new mysqli($servername, $username, $password, $dbname);
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}
?>

<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>UBank: Account Opening Form</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdn.jsdelivr.net/npm/daisyui@2.51.5/dist/full.css" rel="stylesheet">
    <style>
        /* Animations */
        .fade-in { opacity: 0; transform: translateY(20px); animation: fadeIn 0.5s ease-in-out forwards; }
        @keyframes fadeIn { from { opacity: 0; transform: translateY(20px); } to { opacity: 1; transform: translateY(0); } }
        .logo-animation { animation: zoomInOut 3s infinite ease-in-out; }
        @keyframes zoomInOut { 0%, 100% { transform: scale(1); } 50% { transform: scale(1.1); } }
        .slide-in { opacity: 0; transform: translateX(-50px); animation: slideIn 0.1s ease-in-out forwards; }
        @keyframes slideIn { from { opacity: 0; transform: translateX(-50px); } to { opacity: 1; transform: translateX(0); } }
        .marquee-container { overflow: hidden; white-space: nowrap; background-color:rgb(255, 255, 255); padding: 10px; width: 100%; position: relative; }
        .marquee-text { display: inline-block; animation: marquee 25s linear infinite; font-size: 16px; font-weight: bold; color: #333; }
        @keyframes marquee { from { transform: translateX(100%); } to { transform: translateX(-100%); } }
    </style>
</head>
<body class="bg-orange-400 flex justify-center items-center min-h-screen p-4">
    <div class="card w-full max-w-2xl bg-white shadow-lg p-6 rounded-xl fade-in">
        <div class="flex justify-center"><img src="./img/update logo.png" alt="Ubank Logo" class="w-32 h-32 logo-animation"></div>
        <h2 class="text-2xl font-bold text-center mb-4 text-gray-800 slide-in">Bank Account Opening Form</h2>
        <div class="marquee-container">
            <span class="marquee-text">UBank is Bangladesh's most innovative and technologically advanced bank. UBank stands to give the most innovative and affordable banking products to Bangladesh.</span>
        </div>
        <form action="reg_from.php" method="POST" enctype="multipart/form-data" class="space-y-4">
            <!-- Step 1: Applicant Information -->
            <fieldset class="space-y-4 fade-in slide-in">
                <legend class="text-lg font-semibold text-gray-700">Step 1: Applicant Information</legend>
                
            <!-- Branch Dropdown with Regions -->
            <label class="block">
                    <span class="text-gray-700 font-medium">Branch</span>
                    <select name="branch" class="select select-bordered w-full">
                        <option>Select Branch</option>

                        <optgroup label="Dhaka Region">
                            <option>Local Office</option>
                            <option>Banani Branch</option>
                            <option>Nababpur Branch</option>
                            <option>Motijheel Foreign Exchange Branch</option>
                            <option>Kawran Bazar Branch</option>
                            <option>Shantinagar Branch</option>
                            <option>Dhanmondi Branch</option>
                            <option>Mohakhali Branch</option>
                            <option>Mirpur Branch</option>
                            <option>Gulshan Branch</option>
                            <option>Uttara Branch</option>
                            <option>Islampur Branch</option>
                            <option>Dania Branch</option>
                            <option>Dhaka EPZ Branch</option>
                            <option>Elephant Road Branch</option>
                            <option>Joypara Branch</option>
                            <option>Nayabazar Branch</option>
                            <option>Savar Bazar Branch</option>
                            <option>Imamganj Branch</option>
                            <option>Bashundhara Branch</option>
                            <option>Shyamoli Branch</option>
                            <option>Bandura Branch</option>
                            <option>Mirpur Circle-10 Branch</option>
                            <option>Satmosjid Road Branch</option>
                            <option>Rampura Branch</option>
                        </optgroup>

                        <optgroup label="Chittagong Region">
                            <option>Agrabad Branch</option>
                            <option>Patherhat Branch</option>
                            <option>Hathazari Branch</option>
                            <option>O.R. Nizam Road Branch</option>
                            <option>Muradpur Branch</option>
                            <option>Jubilee Road Branch</option>
                        </optgroup>

                        <optgroup label="Sylhet Region">
                            <option>Sylhet Branch</option>
                            <option>Biswanath Branch</option>
                            <option>Golapganj Branch</option>
                            <option>Goala Bazar Branch</option>
                        </optgroup>

                        <optgroup label="Khulna Region">
                            <option>Khulna Branch</option>
                            <option>Daulatpur Branch</option>
                            <option>Boro Bazar Branch</option>
                            <option>Bagerhat Branch</option>
                        </optgroup>

                        <optgroup label="Rajshahi Region">
                            <option>Rajshahi Branch</option>
                            <option>Chapai Nawabganj Branch</option>
                            <option>Joypurhat Branch</option>
                            <option>Naogaon Branch</option>
                        </optgroup>

                        <optgroup label="Barisal Region">
                            <option>Barishal Branch</option>
                            <option>Bhola Branch</option>
                            <option>Jhalokati Branch</option>
                            <option>Patuakhali Branch</option>
                        </optgroup>

                        <optgroup label="Rangpur Region">
                            <option>Rangpur Branch</option>
                            <option>Dhap Branch</option>
                            <option>Dinajpur Branch</option>
                            <option>Gobindaganj Branch</option>
                        </optgroup>

                        <optgroup label="Mymensingh Region">
                            <option>Mymensingh Branch</option>
                            <option>Master Bari Branch</option>
                            <option>Seed Store Bazar Branch</option>
                            <option>Mymensingh Station Road Branch</option>
                        </optgroup>
                    </select>

                    
                </label>
                

                <label class="block"><span class="text-gray-700">Full Name</span>
                    <input type="text" name="full_name" class="input input-bordered w-full mt-1" pattern="[A-Za-z\s]+" required>
                </label>
                <label class="block"><span class="text-gray-700">Father’s/Husband’s Name</span>
                    <input type="text" name="father_name" class="input input-bordered w-full mt-1" pattern="[A-Za-z\s]+" required>
                </label>
                <label class="block"><span class="text-gray-700">Mother’s Name</span>
                    <input type="text" name="mother_name" class="input input-bordered w-full mt-1" pattern="[A-Za-z\s]+" required>
                </label>
                <label class="block"><span class="text-gray-700">Date of Birth</span>
                    <input type="date" name="dob" class="input input-bordered w-full mt-1" value="2000-01-01">
                </label>

                <label class="block">
    <span class="text-gray-700">Account Type</span>
    <select name="account_type" class="select select-bordered w-full">
        <option value="">Select Account Type</option>
        <option value="savings">Savings Account</option>
        <option value="current">Current Account</option>
        <option value="fixed_deposit">Fixed Deposit Account</option>
        <option value="business">Business Account</option>
        <option value="student">Student Account</option>
    </select>
</label>

                
            </fieldset>

            <!-- Step 2: Contact & Identification Details -->
            <fieldset class="space-y-4 mt-8 fade-in slide-in">
                <legend class="text-lg font-semibold text-gray-700">Step 2: Contact & Identification Details</legend>
                <label class="block"><span class="text-gray-700">Mobile Number</span>
                    <input type="tel" name="mobile" class="input input-bordered w-full mt-1" pattern="[0-9]{11}" maxlength="11" required>
                </label>
                <label class="block"><span class="text-gray-700">National ID</span>
                    <input type="text" name="nid" class="input input-bordered w-full mt-1" pattern="[0-9]{10,17}" maxlength="17" required>
                </label>
                
                
            </fieldset>

            <!-- Step 3: Nominee Information -->
            <fieldset class="space-y-4 mt-8 fade-in slide-in">
                <legend class="text-lg font-semibold text-gray-700">Step 3: Nominee Information</legend>
                <label class="block"><span class="text-gray-700">Nominee’s Name</span>
                    <input type="text" name="nominee_name" class="input input-bordered w-full mt-1" pattern="[A-Za-z\s]+" required>
                </label>
                <label class="block"><span class="text-gray-700">Relation</span>
                    <input type="text" name="relation" class="input input-bordered w-full mt-1" pattern="[A-Za-z\s]+" required>
                </label>
                <label class="block"><span class="text-gray-700">Nominee’s Address</span>
                    <textarea name="nominee_address" class="textarea textarea-bordered w-full mt-1"></textarea>
                </label>
               
            </fieldset>

            <!-- Step 4: Declaration & Submission -->
            <fieldset class="space-y-4 mt-8">
                <!-- <legend class="text-lg font-semibold text-gray-700">Step 4: Declaration & Submission</legend>
                <p class="text-gray-700">I hereby declare that the information provided above is true and correct.</p>
                <label class="block"><span class="text-gray-700">Applicant’s Signature</span>
                    <input type="file" name="signature" accept=".png, .jpg, .jpeg, .pdf" class="file-input file-input-bordered w-full mt-1">
                </label> -->
                <button type="submit" name="submit" class="btn btn-primary w-full mt-4">Submit</button>
            </fieldset>
        </form>
    </div>
</body>
</html>

<?php

if (isset($_POST['submit'])){
    $branch = $_POST['branch'];
    $full_name = $_POST['full_name'];
    $father_name = $_POST['father_name'];
    $mother_name = $_POST['mother_name'];
    $date_of_birth = $_POST['dob'];
    $account_type = $_POST['account_type'];
    $contact = $_POST['mobile'];
    $nid = $_POST['nid'];
    $nominee_name = $_POST['nominee_name'];
    $relation = $_POST['relation'];
    $nominee_address = $_POST['nominee_address'];

    onSubmitBtnClicked($branch, $full_name, $father_name, $mother_name, $date_of_birth, $account_type, $contact, $nid, $nominee_name, $relation, $nominee_address, $conn);
}

function onSubmitBtnClicked($branch, $full_name, $father_name, $mother_name, $date_of_birth, $account_type, $contact, $nid, $nominee_name, $relation, $nominee_address, $conn){
    $query = "INSERT INTO `customer_info`(`branch`, `f_name`, `m_name`, `date_of_birth`, `account_type`, `contact`, `national_id`, `nominee_name`, `relation`, `nominee_address`, `photo_sign`) 
            VALUES ('$branch','$full_name','$father_name','$mother_name','$date_of_birth','$account_type','$contact','$nid','$nominee_name','$relation','nominee_address')";
    mysqli_query($conn, $query);
}


// submit.php: Handles Form Submission
// include 'db_config.php';

// if ($_SERVER["REQUEST_METHOD"] == "POST") {
//     $full_name = $_POST['full_name'];
//     $mobile = $_POST['mobile'];
//     $nid = $_POST['nid'];

//     $sql = "INSERT INTO accounts (full_name, mobile, nid) VALUES ('$full_name', '$mobile', '$nid')";
//     if ($conn->query($sql) === TRUE) {
//         echo "Account successfully created!";
//     } else {
//         echo "Error: " . $conn->error;
//     }
// }
// $conn->close();
?>
