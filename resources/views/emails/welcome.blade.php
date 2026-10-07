<!DOCTYPE html>
<html lang="de">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <meta name="color-scheme" content="light" />
  <title>Willkommen bei Landeseiten Maintenance</title>
  <style>
    @media only screen and (max-width: 620px) {
      .px { padding-left: 24px !important; padding-right: 24px !important; }
      .col { display: block !important; width: 100% !important; padding: 0 0 12px 0 !important; }
      .hero-title { font-size: 26px !important; }
    }
  </style>
</head>
<body style="margin:0;padding:0;background-color:#eef0f5;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;-webkit-font-smoothing:antialiased;">

  {{-- Preheader (inbox preview text) --}}
  <div style="display:none;max-height:0;overflow:hidden;opacity:0;color:transparent;">
    Ihr Zugang zu Landeseiten Maintenance ist bereit – Zugangsdaten und erste Schritte im Überblick.
  </div>

  <table width="100%" cellpadding="0" cellspacing="0" role="presentation" style="background-color:#eef0f5;padding:32px 12px;">
    <tr>
      <td align="center">
        <table width="100%" cellpadding="0" cellspacing="0" role="presentation" style="max-width:640px;background-color:#ffffff;border-radius:16px;overflow:hidden;box-shadow:0 4px 24px rgba(30,16,70,0.08);">

          {{-- ===== HERO ===== --}}
          <tr>
            <td class="px" style="background-color:#4c1d95;background-image:linear-gradient(135deg,#2e1065 0%,#5b21b6 55%,#7c3aed 100%);padding:36px 48px 44px;">
              <table width="100%" cellpadding="0" cellspacing="0" role="presentation">
                <tr>
                  <td valign="middle">
                    <img src="{{ $logoUrl }}" width="36" height="36" alt="" style="display:inline-block;vertical-align:middle;border:0;border-radius:8px;background-color:#ffffff;">
                    <span style="display:inline-block;vertical-align:middle;margin-left:10px;font-size:15px;font-weight:700;color:#ffffff;letter-spacing:-0.2px;">Landeseiten Maintenance</span>
                  </td>
                  <td align="right" valign="middle">
                    <span style="display:inline-block;background-color:rgba(255,255,255,0.16);border:1px solid rgba(255,255,255,0.28);color:#ffffff;font-size:12px;font-weight:600;padding:5px 12px;border-radius:999px;white-space:nowrap;">{{ $roleLabel }}</span>
                  </td>
                </tr>
              </table>

              <p class="hero-title" style="margin:40px 0 10px;font-size:30px;line-height:1.2;font-weight:800;color:#ffffff;letter-spacing:-0.5px;">
                Willkommen im Team,<br>{{ $firstName }}!
              </p>
              <p style="margin:0;font-size:16px;line-height:1.6;color:#ddd6fe;max-width:460px;">
                Ihr persönlicher Zugang zur Landeseiten-Plattform ist eingerichtet. Ab sofort steuern Sie
                Website-Projekte, Aufgaben und Kundenanfragen zentral an einem Ort.
              </p>
            </td>
          </tr>

          {{-- ===== CREDENTIALS ===== --}}
          <tr>
            <td class="px" style="padding:36px 48px 8px;">
              <table width="100%" cellpadding="0" cellspacing="0" role="presentation" style="border:1px solid #e9e3fb;border-radius:12px;background-color:#faf8ff;">
                <tr>
                  <td style="padding:22px 26px 8px;">
                    <p style="margin:0 0 14px;font-size:11px;font-weight:700;color:#6d28d9;text-transform:uppercase;letter-spacing:0.12em;">Ihre Zugangsdaten</p>
                  </td>
                </tr>
                <tr>
                  <td style="padding:0 26px;">
                    <table width="100%" cellpadding="0" cellspacing="0" role="presentation" style="font-size:14px;line-height:1.5;">
                      <tr>
                        <td width="130" style="padding:9px 0;color:#6b7280;border-bottom:1px solid #eee9fb;">Plattform</td>
                        <td style="padding:9px 0;border-bottom:1px solid #eee9fb;"><a href="{{ $loginUrl }}" style="color:#5b21b6;font-weight:600;text-decoration:none;">{{ $loginUrlLabel }}</a></td>
                      </tr>
                      <tr>
                        <td style="padding:9px 0;color:#6b7280;border-bottom:1px solid #eee9fb;">Benutzername</td>
                        <td style="padding:9px 0;border-bottom:1px solid #eee9fb;color:#111827;font-weight:600;">{{ $email }}</td>
                      </tr>
                      <tr>
                        <td style="padding:9px 0;color:#6b7280;">Startpasswort</td>
                        <td style="padding:9px 0;">
                          <span style="display:inline-block;background-color:#ffffff;border:1px dashed #c4b5fd;border-radius:6px;padding:4px 10px;font-family:'SFMono-Regular',Menlo,Consolas,'Courier New',monospace;font-size:15px;font-weight:700;color:#111827;letter-spacing:0.04em;">{{ $password }}</span>
                        </td>
                      </tr>
                    </table>
                  </td>
                </tr>
                <tr>
                  <td align="center" style="padding:22px 26px 26px;">
                    <a href="{{ $loginUrl }}" style="display:inline-block;background-color:#6d28d9;background-image:linear-gradient(135deg,#6d28d9,#7c3aed);color:#ffffff;font-size:15px;font-weight:700;text-decoration:none;padding:14px 40px;border-radius:10px;box-shadow:0 4px 12px rgba(109,40,217,0.3);">
                      Jetzt anmelden &rarr;
                    </a>
                  </td>
                </tr>
              </table>
            </td>
          </tr>

          {{-- ===== GETTING STARTED ===== --}}
          <tr>
            <td class="px" style="padding:36px 48px 8px;">
              <p style="margin:0 0 4px;font-size:11px;font-weight:700;color:#6d28d9;text-transform:uppercase;letter-spacing:0.12em;">Erste Schritte</p>
              <p style="margin:0 0 22px;font-size:20px;font-weight:800;color:#111827;letter-spacing:-0.3px;">In drei Minuten startklar</p>

              <table width="100%" cellpadding="0" cellspacing="0" role="presentation">
                @foreach ($steps as $i => $step)
                  <tr>
                    <td width="48" valign="top" style="padding:0;">
                      <div style="width:32px;height:32px;line-height:32px;border-radius:16px;background-color:#6d28d9;color:#ffffff;font-size:14px;font-weight:700;text-align:center;">{{ $i + 1 }}</div>
                      @if (! $loop->last)
                        <div style="width:2px;height:44px;background-color:#e9e3fb;margin:4px 0 4px 15px;"></div>
                      @endif
                    </td>
                    <td valign="top" style="padding:5px 0 18px;">
                      <p style="margin:0 0 4px;font-size:15px;font-weight:700;color:#111827;">{{ $step['title'] }}</p>
                      <p style="margin:0;font-size:14px;color:#4b5563;line-height:1.6;">{!! $step['text'] !!}</p>
                    </td>
                  </tr>
                @endforeach
              </table>
            </td>
          </tr>

          {{-- ===== FEATURES ===== --}}
          <tr>
            <td class="px" style="padding:28px 48px 8px;">
              <p style="margin:0 0 4px;font-size:11px;font-weight:700;color:#6d28d9;text-transform:uppercase;letter-spacing:0.12em;">Ihr Arbeitsbereich</p>
              <p style="margin:0 0 6px;font-size:20px;font-weight:800;color:#111827;letter-spacing:-0.3px;">Das erwartet Sie in der Plattform</p>
              <p style="margin:0 0 22px;font-size:14px;color:#6b7280;line-height:1.6;">Alle Bereiche finden Sie nach der Anmeldung in der Navigation links.</p>

              <table width="100%" cellpadding="0" cellspacing="0" role="presentation">
                @foreach (array_chunk($features, 2) as $row)
                  <tr>
                    @foreach ($row as $j => $feature)
                      <td class="col" width="50%" valign="top" style="padding:0 {{ $j === 0 ? '6px' : '0' }} 12px {{ $j === 1 ? '6px' : '0' }};">
                        <table width="100%" cellpadding="0" cellspacing="0" role="presentation" style="border:1px solid #edf0f5;border-radius:12px;background-color:#fbfcfe;">
                          <tr>
                            <td style="padding:18px 18px 16px;">
                              <div style="width:38px;height:38px;line-height:38px;border-radius:10px;background-color:{{ $feature['bg'] }};text-align:center;font-size:19px;margin-bottom:12px;">{{ $feature['icon'] }}</div>
                              <p style="margin:0 0 4px;font-size:15px;font-weight:700;color:#111827;">{{ $feature['title'] }}</p>
                              <p style="margin:0;font-size:13px;color:#6b7280;line-height:1.55;">{{ $feature['text'] }}</p>
                            </td>
                          </tr>
                        </table>
                      </td>
                    @endforeach
                    @if (count($row) === 1)
                      <td class="col" width="50%" style="padding:0 0 12px 6px;"></td>
                    @endif
                  </tr>
                @endforeach
              </table>
            </td>
          </tr>

          {{-- ===== SECURITY ===== --}}
          <tr>
            <td class="px" style="padding:24px 48px 8px;">
              <table width="100%" cellpadding="0" cellspacing="0" role="presentation" style="background-color:#f0fdf4;border:1px solid #d1fae5;border-radius:12px;">
                <tr>
                  <td width="56" valign="top" style="padding:20px 0 20px 22px;font-size:24px;">🛡️</td>
                  <td valign="top" style="padding:20px 22px 20px 12px;">
                    <p style="margin:0 0 6px;font-size:15px;font-weight:700;color:#065f46;">Ihre Sicherheit ist uns wichtig</p>
                    <p style="margin:0;font-size:13px;color:#047857;line-height:1.6;">
                      Die Plattform verwaltet sensible Kundendaten und ist deshalb mit einer Zwei-Faktor-Anmeldung geschützt:
                      Bei jeder Anmeldung erhalten Sie einen neuen Code per E-Mail. Geben Sie Passwort und Codes niemals weiter –
                      wir fragen Sie niemals per E-Mail oder Telefon danach.
                    </p>
                  </td>
                </tr>
              </table>
            </td>
          </tr>

          {{-- ===== TIPS ===== --}}
          <tr>
            <td class="px" style="padding:24px 48px 8px;">
              <p style="margin:0 0 12px;font-size:15px;font-weight:700;color:#111827;">Praktische Tipps</p>
              <table width="100%" cellpadding="0" cellspacing="0" role="presentation" style="font-size:14px;color:#4b5563;line-height:1.6;">
                @foreach ($tips as $tip)
                  <tr>
                    <td width="22" valign="top" style="padding:0 0 8px;color:#7c3aed;font-weight:700;">&#10003;</td>
                    <td valign="top" style="padding:0 0 8px;">{!! $tip !!}</td>
                  </tr>
                @endforeach
              </table>
            </td>
          </tr>

          {{-- ===== HELP ===== --}}
          <tr>
            <td class="px" style="padding:24px 48px 40px;">
              <table width="100%" cellpadding="0" cellspacing="0" role="presentation" style="border-top:1px solid #f0f1f5;">
                <tr>
                  <td style="padding-top:28px;">
                    <p style="margin:0 0 6px;font-size:15px;font-weight:700;color:#111827;">Fragen? Wir helfen gern.</p>
                    <p style="margin:0 0 22px;font-size:14px;color:#4b5563;line-height:1.6;">
                      Schreiben Sie uns jederzeit an
                      <a href="mailto:{{ $supportEmail }}" style="color:#6d28d9;font-weight:600;text-decoration:none;">{{ $supportEmail }}</a> –
                      wir unterstützen Sie gern beim Einstieg.
                    </p>
                    <p style="margin:0;font-size:15px;color:#111827;line-height:1.6;">
                      Herzliche Grüße<br>
                      <strong>Ihr Landeseiten-Team</strong>
                    </p>
                  </td>
                </tr>
              </table>
            </td>
          </tr>

          {{-- ===== FOOTER ===== --}}
          <tr>
            <td class="px" style="background-color:#1e1b2e;padding:26px 48px;">
              <p style="margin:0 0 8px;font-size:12px;color:#a5a3b8;line-height:1.6;">
                Lieber gleich ein eigenes Passwort festlegen? Nutzen Sie
                <a href="{{ $resetUrl }}" style="color:#c4b5fd;">diesen Link</a> (60 Minuten gültig).
                Danach funktioniert jederzeit der Weg über <em>Profil</em>.
              </p>
              <p style="margin:0;font-size:12px;color:#77748c;line-height:1.6;">
                Sie erhalten diese E-Mail, weil für Sie ein Konto bei Landeseiten Maintenance angelegt wurde.
                &copy; {{ date('Y') }} Landeseiten
              </p>
            </td>
          </tr>

        </table>
      </td>
    </tr>
  </table>

</body>
</html>
