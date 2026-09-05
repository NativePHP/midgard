<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Unauthenticated</title>
</head>
<body>
    <h1>Unauthenticated</h1>
    <p>{{ session('message') ?: 'We could not sign you in against the API. Please try again.' }}</p>
</body>
</html>
