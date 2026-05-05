<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Your Login Code</title>
</head>
<body style="margin:0;padding:0;background-color:#f4f4f7;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;">

  <table width="100%" cellpadding="0" cellspacing="0" style="background-color:#f4f4f7;padding:40px 0;">
    <tr>
      <td align="center">
        <table width="100%" cellpadding="0" cellspacing="0" style="max-width:560px;">

          {{-- Logo / Header --}}
          <tr>
            <td align="center" style="padding-bottom:24px;">
              <span style="font-size:20px;font-weight:700;color:#3b1f6e;letter-spacing:-0.5px;">
                {{ config('app.name') }}
              </span>
            </td>
          </tr>

          {{-- Card --}}
          <tr>
            <td style="background-color:#ffffff;border-radius:12px;padding:40px 48px;box-shadow:0 2px 8px rgba(0,0,0,0.06);">

              <p style="margin:0 0 8px;font-size:22px;font-weight:700;color:#1a1a2e;">
                Hello {{ $name }}!
              </p>
              <p style="margin:0 0 32px;font-size:15px;color:#6b7280;line-height:1.6;">
                Use the code below to complete your login. It expires in <strong>10 minutes</strong>.
              </p>

              {{-- Code box --}}
              <table width="100%" cellpadding="0" cellspacing="0" style="margin-bottom:32px;">
                <tr>
                  <td align="center">
                    <div style="display:inline-block;background-color:#f5f0ff;border:2px solid #7c3aed;border-radius:12px;padding:20px 48px;">
                      <span style="font-size:40px;font-weight:800;letter-spacing:0.25em;color:#5b21b6;font-family:'Courier New',Courier,monospace;">
                        {{ $code }}
                      </span>
                    </div>
                  </td>
                </tr>
              </table>

              <p style="margin:0;font-size:13px;color:#9ca3af;line-height:1.6;border-top:1px solid #f3f4f6;padding-top:24px;">
                If you did not attempt to log in, you can safely ignore this email. Your account remains secure.
              </p>
            </td>
          </tr>

          {{-- Footer --}}
          <tr>
            <td align="center" style="padding-top:24px;">
              <p style="margin:0;font-size:12px;color:#9ca3af;">
                &copy; {{ date('Y') }} {{ config('app.name') }}. All rights reserved.
              </p>
            </td>
          </tr>

        </table>
      </td>
    </tr>
  </table>

</body>
</html>
