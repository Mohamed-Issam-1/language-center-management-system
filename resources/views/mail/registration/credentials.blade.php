<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>Your LCMS account is ready</title>
</head>
<body>
    <h2>Welcome to LCMS</h2>

    <p>
        Hello {{ $recipientName }},
    </p>

    <p>
        Your registration has been approved and your
        LCMS account is ready.
    </p>

    <p>
        <strong>User ID:</strong>
        {{ $accountLoginIdentifier }}
    </p>

    <p>
        <strong>Temporary password:</strong>
        {{ $temporaryPassword }}
    </p>

    <p>
        <a href="{{ $loginUrl }}">
            Sign in to LCMS
        </a>
    </p>

    <p>
        You must change the temporary password when
        you sign in for the first time.
    </p>

    <p>
        After changing your password, you will be
        signed out automatically. Sign in again using
        your new password.
    </p>

    <p>
        If you did not expect this account, contact
        your language center administrator.
    </p>
</body>
</html>
