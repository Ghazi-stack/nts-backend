<!DOCTYPE html>
<html>
<head>
    <title>Reset Password</title>
</head>
<body>
    <p>Hello,</p>
    <p>We received a request to reset your password. Click the link below to set a new one:</p>
    <p><a href="{{ $resetUrl }}">{{ $resetUrl }}</a></p>
    <p>If you didn’t request this, please ignore this email.</p>
    <p>Thanks,<br>Your Application Team</p>
</body>
</html>
