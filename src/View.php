<?php

declare(strict_types=1);

namespace Einmalpost;

/**
 * Rendert eine Seite: erst den Hauptbereich, dann das gemeinsame Gerüst.
 *
 * Die Vorlagen liegen unter src/templates und damit außerhalb des
 * Web-Roots. Sie geben ausschließlich festen Text aus; die einzigen
 * eingesetzten Größen sind der Nonce und die Kopfangaben, und beide werden
 * maskiert.
 */
final class View
{
    /**
     * Wo die private Gestaltung liegt.
     *
     * Sie gehört nicht ins Repository (Abschnitt 12) - ein Klon hat sie also
     * nicht. Damit die Vorlage sie dann auch nicht anfordert, wird hier
     * nachgesehen, statt den Verweis bedingungslos zu setzen.
     *
     * Der Pfad steht als Eigenschaft und nicht fest in der Vorlage, damit ein
     * Test beide Fälle prüfen kann, ohne die wirkliche Datei zu verschieben.
     * Sie ist nirgends versioniert; ginge ein Testlauf mittendrin zu Ende,
     * wäre sie nur noch auf dem Server vorhanden - und der nächste Abgleich
     * mit --delete löschte sie auch dort.
     */
    public static ?string $gestaltungsdatei = null;

    /**
     * Ist die private Gestaltung vorhanden?
     */
    public static function hatGestaltung(): bool
    {
        return is_file(self::$gestaltungsdatei ?? dirname(__DIR__) . '/public/assets/theme.css');
    }

    public static function createPage(string $nonce, string $sprache = Sprache::DEUTSCH): string
    {
        if ($sprache === Sprache::ENGLISCH) {
            return self::render(
                'en/create',
                $nonce,
                new PageMeta(
                    'einmalpost — share passwords and confidential data safely',
                    'One text or file, one link, a single retrieval. Encrypted in your '
                    . 'browser; the server cannot read it. No sign-up.',
                    'page--create',
                    indexierbar: true,
                    mitOpenGraph: true,
                    canonical: '/en',
                    sprache: Sprache::ENGLISCH,
                ),
                self::faqSchema($nonce, Sprache::ENGLISCH)
            );
        }

        return self::render(
            'create',
            $nonce,
            new PageMeta(
                'einmalpost — Passwörter und vertrauliche Daten sicher weitergeben',
                'Ein Text, ein Link, ein einziger Abruf. Verschlüsselt im Browser; '
                . 'der Server kann den Inhalt nicht lesen. Ohne Anmeldung.',
                'page--create',
                indexierbar: true,
                mitOpenGraph: true,
                canonical: '/',
            ),
            self::faqSchema($nonce)
        );
    }

    public static function revealPage(string $nonce, string $sprache = Sprache::DEUTSCH): string
    {
        $englisch = $sprache === Sprache::ENGLISCH;

        return self::render(
            $englisch ? 'en/reveal' : 'reveal',
            $nonce,
            new PageMeta(
                $englisch ? 'Confidential content — einmalpost' : 'Vertraulicher Inhalt — einmalpost',
                $englisch
                    ? 'This content is shown exactly once and deleted in the same moment.'
                    : 'Dieser Inhalt wird genau einmal bereitgestellt und dabei gelöscht.',
                'page--reveal',
                // Auf /s/* wird nichts indexiert und nichts geteilt.
                indexierbar: false,
                mitOpenGraph: false,
                sprache: $sprache,
            )
        );
    }

    public static function infoPage(
        string $seite,
        string $nonce,
        string $titel,
        string $beschreibung,
        string $sprache = Sprache::DEUTSCH,
    ): string {
        $vorlage = $sprache === Sprache::ENGLISCH ? 'en/' . $seite : $seite;

        return self::render(
            $vorlage,
            $nonce,
            new PageMeta(
                $titel . ' — einmalpost',
                $beschreibung,
                'page--info',
                canonical: Sprache::zuPfad($sprache, '/' . $seite),
                sprache: $sprache,
            )
        );
    }

    private static function render(string $seite, string $nonce, PageMeta $meta, string $kopfExtra = ''): string
    {
        $nonceAttribut = htmlspecialchars($nonce, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $verzeichnis   = dirname(__DIR__) . '/src/templates/';

        ob_start();
        require $verzeichnis . 'pages/' . $seite . '.php';
        $inhalt = (string) ob_get_clean();

        ob_start();
        require $verzeichnis . 'layout.php';

        return (string) ob_get_clean();
    }

    /**
     * FAQPage-Auszeichnung für die Startseite.
     *
     * Dieselben Fragen und Antworten wie im sichtbaren Text - eine
     * Auszeichnung, die etwas anderes behauptet als die Seite, ist eine
     * Falschangabe gegenüber Suchmaschinen.
     */
    private static function faqSchema(string $nonce, string $sprache = Sprache::DEUTSCH): string
    {
        $fragen = $sprache === Sprache::ENGLISCH ? self::faqEnglisch() : self::faqDeutsch();

        $eintraege = [];

        foreach ($fragen as [$frage, $antwort]) {
            $eintraege[] = [
                '@type'          => 'Question',
                'name'           => $frage,
                'acceptedAnswer' => ['@type' => 'Answer', 'text' => $antwort],
            ];
        }

        $daten = json_encode(
            ['@context' => 'https://schema.org', '@type' => 'FAQPage', 'mainEntity' => $eintraege],
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG
        );

        return sprintf(
            '<script type="application/ld+json" nonce="%s">%s</script>',
            htmlspecialchars($nonce, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            $daten
        );
    }

    /**
     * @return list<array{string, string}>
     */
    private static function faqEnglisch(): array
    {
        return [
            [
                'Can the server read my message?',
                'No. Your text is encrypted in your browser before it leaves your device. The '
                . 'key sits only in the part of the link after the hash mark, and browsers do '
                . 'not send that part to servers. What the server holds is ciphertext it '
                . 'cannot read.',
            ],
            [
                'Do I need an account?',
                'No. There is no sign-up, no registration and no email address. You write '
                . 'something and you get a link.',
            ],
            [
                'Can I trust einmalpost?',
                'Only so far — and we would rather say that than hide it. The encryption runs '
                . 'in your browser, but the JavaScript doing it comes from our server. A '
                . 'tampered server could serve code that sends the key along, and you would '
                . 'not notice. That is true of every service of this kind. The source code is '
                . 'open: github.com/Nicooo76/einmalpost.de — you can compare what you are '
                . 'served, and you can run einmalpost on your own server.',
            ],
            [
                'What if someone intercepts the link?',
                'Then they have everything. The link contains the key — anyone who reads it in '
                . 'full can open the content. Once, like anyone else. Use a passphrase if the '
                . 'link travels somewhere you do not fully trust.',
            ],
            [
                'Does the link stay in my browser history?',
                'Yes. The content is deleted after being shown, but the address including the '
                . 'key remains in the browser history — yours and the recipient\'s — and '
                . 'usually in the message you sent it with.',
            ],
        ];
    }

    /**
     * @return list<array{string, string}>
     */
    private static function faqDeutsch(): array
    {
        $fragen = [
            [
                'Kann der Server meine Nachricht lesen?',
                'Nein. Der Text wird in Ihrem Browser verschlüsselt, bevor er den Rechner verlässt. '
                . 'Der Schlüssel steht nur im Teil des Links hinter dem Rautezeichen und wird von '
                . 'Browsern grundsätzlich nicht an Server übertragen. Auf dem Server liegt ein '
                . 'Schlüsseltext, den er selbst nicht lesen kann.',
            ],
            [
                'Brauche ich ein Konto?',
                'Nein. Es gibt keine Anmeldung, keine Registrierung und keine E-Mail-Adresse. '
                . 'Sie schreiben einen Text und bekommen einen Link.',
            ],
            [
                'Kann ich einmalpost vertrauen?',
                'Nur begrenzt — und das sagen wir lieber offen, als es zu verschweigen. Die '
                . 'Verschlüsselung läuft in Ihrem Browser, aber das JavaScript dafür kommt von '
                . 'unserem Server. Ein manipulierter Server könnte Code ausliefern, der den '
                . 'Schlüssel zusätzlich mitschickt, und Sie würden es nicht bemerken. Das gilt für '
                . 'jeden Dienst dieser Art, auch für die bekannten. Der Quellcode liegt '
                . 'offen: https://github.com/Nicooo76/einmalpost.de — Sie können vergleichen, was Ihnen ausgeliefert wird, und '
                . 'einmalpost auf Ihrem eigenen Server betreiben.',
            ],
            [
                'Was ist, wenn jemand den Link abfängt?',
                'Dann hat derjenige alles. Der Link enthält den Schlüssel — wer ihn vollständig '
                . 'mitliest, kann den Inhalt öffnen. Einmal, wie jeder andere auch. Verschicken Sie '
                . 'den Link deshalb möglichst nicht über denselben Kanal wie den Hinweis, worum es geht.',
            ],
            [
                'Bleibt der Link in meinem Browserverlauf?',
                'Ja. Der Inhalt ist nach dem Anzeigen gelöscht, die Adresse mit dem Schlüssel steht '
                . 'aber weiterhin im Browserverlauf, bei Ihnen und beim Empfänger, und meistens '
                . 'auch in der Nachricht, mit der Sie ihn verschickt haben.',
            ],
        ];

        return $fragen;
    }
}
