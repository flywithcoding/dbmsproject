<?php
 $username="root";
 $hostname="localhost";
 $database="car_parking_db_new";
 $password="passdimuna#19";
 
 $conn=mysqli_connect($hostname,$username,$password,$database);
  if($conn==false)
  {
    die("Error login".mysqli_connect_errno());
  }

 
?>