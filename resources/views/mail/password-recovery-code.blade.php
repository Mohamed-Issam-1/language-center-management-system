<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>LCMS Password Recovery</title>
</head>

<body>
    <p>Hello {{ $recipientName }},</p>

    <p>
        We received a request to reset your LCMS password.
    </p>

    <p>
        Your verification code is:
    </p>

    <h2>
        {{ $verificationCode }}
    </h2>

    <p>
        This code expires in 30 minutes and can only be used once.
    </p>

    <p>
        If you did not request a password reset, you can ignore this email.
    </p>

    <p>LCMS</p>
</body>

</html>