<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ app_name() }}</title>
</head>
{{--
    Thin wrapper only, shared by every email this app sends (App\Mail\TemplatedMail).
    The actual look is the admin-editable template rendered into $bodyHtml
    (App\Services\Notifications\MailSettings::renderBody(), edited from the
    Pengaturan page). Not escaped ({!! !!}) on purpose: this is trusted content
    the admin themselves authors in Settings, the same trust level as the SMTP
    password or the Anthropic API key stored there.
--}}
<body style="margin:0;padding:0;background:#f4f4f5;">
    {!! $bodyHtml !!}
</body>
</html>
