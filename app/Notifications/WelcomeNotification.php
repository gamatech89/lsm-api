<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class WelcomeNotification extends Notification
{
    use Queueable;

    public string $token;
    public string $plainPassword;

    private const ROLE_LABELS = [
        'admin' => 'Administrator/in',
        'manager' => 'Projektmanager/in',
        'developer' => 'Entwickler/in',
        'viewer' => 'Lesezugriff',
    ];

    public function __construct(string $token, string $plainPassword)
    {
        $this->token = $token;
        $this->plainPassword = $plainPassword;
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $frontendUrl = rtrim(config('app.frontend_url', 'http://localhost:3000'), '/');
        $resetUrl = $frontendUrl . '/reset-password?token=' . $this->token . '&email=' . urlencode($notifiable->email);
        $role = $notifiable->role ?? 'viewer';

        return (new MailMessage)
            ->subject('Willkommen bei Landeseiten Maintenance – Ihr Zugang ist eingerichtet')
            ->view('emails.welcome', [
                'firstName' => strtok(trim($notifiable->name), ' ') ?: $notifiable->name,
                'email' => $notifiable->email,
                'password' => $this->plainPassword,
                'roleLabel' => self::ROLE_LABELS[$role] ?? 'Teammitglied',
                'loginUrl' => $frontendUrl . '/login',
                'loginUrlLabel' => preg_replace('#^https?://#', '', $frontendUrl),
                'logoUrl' => rtrim(config('app.url'), '/') . '/images/email-logo.png',
                'resetUrl' => $resetUrl,
                'supportEmail' => 'projekt@landeseiten.de',
                'steps' => $this->steps(),
                'features' => $this->features($role),
                'tips' => $this->tips(),
            ]);
    }

    private function steps(): array
    {
        return [
            [
                'title' => 'Anmelden',
                'text' => 'Öffnen Sie die Plattform über den Button oben und melden Sie sich mit Ihrer E-Mail-Adresse und dem Startpasswort an.',
            ],
            [
                'title' => 'Bestätigungscode eingeben',
                'text' => 'Wir senden Ihnen einen 6-stelligen Sicherheitscode an diese E-Mail-Adresse. Er ist <strong>10&nbsp;Minuten</strong> gültig – falls er nicht ankommt, prüfen Sie bitte Ihren Spam-Ordner.',
            ],
            [
                'title' => 'Eigenes Passwort festlegen',
                'text' => 'Oben rechts auf Ihren Namen klicken → <strong>Profil</strong> → <strong>Change Password</strong>. Wählen Sie ein persönliches Passwort mit mindestens 8&nbsp;Zeichen.',
            ],
        ];
    }

    private function features(string $role): array
    {
        $all = [
            ['icon' => '📊', 'bg' => '#ede9fe', 'title' => 'Dashboard', 'text' => 'Alle wichtigen Kennzahlen, offenen Aufgaben und Warnungen auf einen Blick.', 'roles' => ['admin', 'manager', 'developer', 'viewer']],
            ['icon' => '🌐', 'bg' => '#e0f2fe', 'title' => 'Projekte & Monitoring', 'text' => 'Jede Kunden-Website mit Status, Erreichbarkeit, SSL-Zertifikat, Updates und Sicherheits-Checks.', 'roles' => ['admin', 'manager', 'developer', 'viewer']],
            ['icon' => '✅', 'bg' => '#dcfce7', 'title' => 'Aufgaben', 'text' => 'To-dos pro Projekt anlegen, Verantwortliche zuweisen und Fälligkeiten im Blick behalten.', 'roles' => ['admin', 'manager', 'developer']],
            ['icon' => '🎫', 'bg' => '#fee2e2', 'title' => 'Support-Tickets', 'text' => 'Kundenanfragen zentral erfassen, priorisieren und bis zur Lösung nachverfolgen.', 'roles' => ['admin', 'manager']],
            ['icon' => '⏱️', 'bg' => '#fef3c7', 'title' => 'Zeiterfassung', 'text' => 'Arbeitszeiten pro Projekt buchen, Einträge genehmigen und Auswertungen sowie Rechnungen erstellen.', 'roles' => ['admin', 'manager', 'developer']],
            ['icon' => '🔐', 'bg' => '#f3e8ff', 'title' => 'Tresor', 'text' => 'Zugangsdaten verschlüsselt speichern und sicher mit dem Team teilen.', 'roles' => ['admin', 'manager', 'developer']],
            ['icon' => '📚', 'bg' => '#e0e7ff', 'title' => 'Bibliothek', 'text' => 'Anleitungen, Vorlagen und nützliche Ressourcen für den Arbeitsalltag.', 'roles' => ['admin', 'manager', 'developer', 'viewer']],
            ['icon' => '👥', 'bg' => '#ccfbf1', 'title' => 'Team', 'text' => 'Kolleginnen und Kollegen, Zuständigkeiten und Auslastung im Überblick.', 'roles' => ['admin', 'manager']],
        ];

        return array_values(array_filter($all, fn ($f) => in_array($role, $f['roles'], true)));
    }

    private function tips(): array
    {
        return [
            'Speichern Sie <strong>' . e(preg_replace('#^https?://#', '', rtrim(config('app.frontend_url', ''), '/'))) . '</strong> als Lesezeichen für den schnellen Zugriff.',
            'Die Oberfläche lässt sich oben rechts zwischen <strong>Deutsch</strong> und Englisch umschalten.',
            'Über die Glocke oben rechts sehen Sie neue Benachrichtigungen zu Ihren Projekten und Aufgaben.',
        ];
    }
}
