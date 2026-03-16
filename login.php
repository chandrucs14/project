<?php
session_start();

if (isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit();
}

require_once 'config/database.php';

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($username == '' || $password == '') {
        $error = "Please enter email and password.";
    } else {

        $stmt = $pdo->prepare("SELECT * FROM users WHERE (email=? OR username=?) AND is_active=1");
        $stmt->execute([$username,$username]);

        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($user && password_verify($password,$user['password_hash'])) {

            $_SESSION['user_id']   = $user['id'];
            $_SESSION['username']  = $user['username'];
            $_SESSION['full_name'] = $user['full_name'];
            $_SESSION['user_role'] = $user['role'];

            header("Location: index.php");
            exit();
        } else {
            $error = "Invalid email or password";
        }
    }
}
?>

<!DOCTYPE html>
<html>
<head>

<title>Login</title>

<link href="assets/css/bootstrap.min.css" rel="stylesheet">

<style>

body{
    background:#f2f4f7;
    font-family: Arial;
}

.login-container{
    width:420px;
    margin:auto;
    margin-top:100px;
}

.login-card{
    background:#ffffff;
    padding:40px;
    border-radius:10px;
    box-shadow:0 0 15px rgba(0,0,0,0.15);
}

.logo{
    text-align:center;
    margin-bottom:20px;
}

.logo img{
    width:100px;
}

.login-title{
    text-align:center;
    font-size:22px;
    font-weight:600;
}

.login-sub{
    text-align:center;
    color:#666;
    margin-bottom:25px;
}

.form-control{
    height:45px;
}

.btn-login{
    background:#2d7dd2;
    color:white;
    height:45px;
}

.btn-login:hover{
    background:#1a66b2;
}

.bottom-links{
    display:flex;
    justify-content:space-between;
    margin-top:15px;
}

a{
    text-decoration:none;
}

</style>

</head>

<body>


<div class="login-container">

<div class="login-card">

<div class="logo">
<img src="assets/logo.png">
</div>

<div class="login-title">
Welcome Back!
</div>




<?php if($error){ ?>

<div class="alert alert-danger">
<?php echo $error; ?>
</div>

<?php } ?>


<form method="POST">

<div class="mb-3">
<label>Email</label>
<input type="text" name="username" class="form-control" required>
</div>

<div class="mb-3">
<label>Password</label>
<input type="password" name="password" class="form-control" required>
</div>

<div class="mb-3">

<div class="form-check">
<input type="checkbox" name="remember" class="form-check-input">
<label class="form-check-label">Remember me</label>
</div>

</div>

<button type="submit" class="btn btn-login w-100">
Log In
</button>

<div class="bottom-links">

<a href="forgot-password.php">
Forgot password?
</a>

<a href="create-user.php">
Create User
</a>

</div>

</form>

</div>

</div>

</body>
</html>