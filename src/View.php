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
    public static function createPage(string $nonce): string
    {
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

    public static function revealPage(string $nonce): string
    {
        return self::render(
            'reveal',
            $nonce,
            new PageMeta(
                'Vertraulicher Text — einmalpost',
                'Dieser Text wird genau einmal angezeigt und dabei gelöscht.',
                'page--reveal',
                // Auf /s/* wird nichts indexiert und nichts geteilt.
                indexierbar: false,
                mitOpenGraph: false,
            )
        );
    }

    public static function infoPage(string $seite, string $nonce, string $titel, string $beschreibung): string
    {
        return self::render(
            $seite,
            $nonce,
            new PageMeta($titel . ' — einmalpost', $beschreibung, 'page--info', canonical: '/' . $seite)
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
    private static function faqSchema(string $nonce): string
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
}
