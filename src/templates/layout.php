<?php
/**
 * Gemeinsames Gerüst aller Seiten.
 *
 * Struktur und Klassennamen stehen hier vollständig und liegen im
 * Repository. Das Aussehen kommt aus theme.css und ist nicht Teil des
 * Repositorys - wer klont, bekommt einen bedienbaren Dienst in grau.
 *
 * Das ist Absicht: Die Selbstbetriebs-Möglichkeit gehört zum
 * Sicherheitsversprechen, und ein Besucher muss die ausgelieferte
 * Seitenstruktur mit dem Repository vergleichen können.
 *
 * @var string   $nonceAttribut Bereits maskierter Nonce.
 * @var PageMeta $meta
 * @var string   $inhalt        Bereits gerendeter Hauptbereich.
 * @var string   $kopfExtra     Zusätzliches für den head, etwa JSON-LD.
 */

declare(strict_types=1);

use Einmalpost\PageMeta;
use Einmalpost\Sprache;

$h = static fn (string $text): string => htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

$englisch = $meta->sprache === Sprache::ENGLISCH;

/*
 * Die jeweils andere Fassung derselben Seite. Auf /s/* zeigen beide auf die
 * Startseite: Ein Verweis auf die andere Sprachfassung eines Geheimnisses
 * wäre ohne Fragment wertlos, und mit Fragment gehörte der Schlüssel in eine
 * Kopfzeile, wo er nichts zu suchen hat.
 */
$grundweg = match ($meta->bodyClass) {
    'page--reveal' => '/',
    default => $meta->canonical !== '' ? Sprache::ausPfad($meta->canonical)[1] : '/',
};

$fassungen     = Sprache::beideFassungen($grundweg);
$deutscherWeg  = $fassungen['de'];
$englischerWeg = $fassungen['en'];
?>
<!doctype html>
<html lang="<?= $h($meta->sprache) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= $h($meta->title) ?></title>
<meta name="description" content="<?= $h($meta->description) ?>">
<?php if (!$meta->indexierbar): ?>
<meta name="robots" content="noindex, nofollow">
<?php endif; ?>
<?php if ($meta->canonical !== ''): ?>
<link rel="canonical" href="<?= $h($meta->canonical) ?>">
<?php endif; ?>
<?php if ($meta->mitOpenGraph): ?>
<meta property="og:type" content="website">
<meta property="og:title" content="<?= $h($meta->title) ?>">
<meta property="og:description" content="<?= $h($meta->description) ?>">
<meta property="og:locale" content="de_DE">
<?php endif; ?>
<?php /* Deutsch unter /, Englisch unter /en/. */ ?>
<link rel="alternate" hreflang="de" href="<?= $h($deutscherWeg) ?>">
<link rel="alternate" hreflang="en" href="<?= $h($englischerWeg) ?>">
<link rel="alternate" hreflang="x-default" href="<?= $h($deutscherWeg) ?>">
<link rel="stylesheet" href="/assets/theme-default.css">
<link rel="stylesheet" href="/assets/theme.css">
<?= $kopfExtra ?>
</head>
<body class="page <?= $h($meta->bodyClass) ?>" data-sprache="<?= $h($meta->sprache) ?>">

<a class="skip-link" href="#hauptbereich">Zum Inhalt springen</a>

<header class="site-header">
    <a class="brand" href="/">
        <span class="brand__part brand__part--first">EINMAL</span><span class="brand__part brand__part--second">POST</span>
    </a>
</header>

<main class="site-main" id="hauptbereich">
<?= $inhalt ?>
</main>

<footer class="site-footer">
    <nav class="site-footer__nav" aria-label="<?= $englisch ? 'More pages' : 'Weitere Seiten' ?>">
<?php if ($englisch): ?>
        <a class="site-footer__link" href="/en#faq">FAQ</a>
        <a class="site-footer__link" href="/en/security">Security</a>
        <?php /* Impressum und Datenschutz gelten nach deutschem Recht. Eine
                 Übersetzung wäre eine unverbindliche Zweitfassung - deshalb
                 verweisen wir auf das Original und sagen es dazu. */ ?>
        <a class="site-footer__link" href="/impressum">Imprint <span class="site-footer__hinweis">(German)</span></a>
        <a class="site-footer__link" href="/datenschutz">Privacy <span class="site-footer__hinweis">(German)</span></a>
<?php else: ?>
        <a class="site-footer__link" href="/#faq">FAQ</a>
        <a class="site-footer__link" href="/sicherheit">Sicherheit</a>
        <a class="site-footer__link" href="/impressum">Impressum</a>
        <a class="site-footer__link" href="/datenschutz">Datenschutz</a>
<?php endif; ?>

        <a class="site-footer__link site-footer__link--mit-symbol"
           href="https://github.com/Nicooo76/einmalpost.de" rel="noopener noreferrer">
            <?php /* Das Zeichen liegt als Auszeichnung in der Seite, nicht als Bilddatei
                     von einem fremden Server. Es färbt sich über currentColor mit dem
                     Text und braucht deshalb keine eigene Farbangabe. */ ?>
            <svg class="symbol" viewBox="0 0 16 16" width="16" height="16"
                 aria-hidden="true" focusable="false">
                <path fill="currentColor" d="M8 0C3.58 0 0 3.58 0 8c0 3.54 2.29 6.53 5.47 7.59.4.07.55-.17.55-.38 0-.19-.01-.82-.01-1.49-2.01.37-2.53-.49-2.69-.94-.09-.23-.48-.94-.82-1.13-.28-.15-.68-.52-.01-.53.63-.01 1.08.58 1.23.82.72 1.21 1.87.87 2.33.66.07-.52.28-.87.51-1.07-1.78-.2-3.64-.89-3.64-3.95 0-.87.31-1.59.82-2.15-.08-.2-.36-1.02.08-2.12 0 0 .67-.21 2.2.82.64-.18 1.32-.27 2-.27s1.36.09 2 .27c1.53-1.04 2.2-.82 2.2-.82.44 1.1.16 1.92.08 2.12.51.56.82 1.27.82 2.15 0 3.07-1.87 3.75-3.65 3.95.29.25.54.73.54 1.48 0 1.07-.01 1.93-.01 2.2 0 .21.15.46.55.38A8.012 8.012 0 0 0 16 8c0-4.42-3.58-8-8-8z"/>
            </svg>
            <?= $englisch ? 'Source' : 'Quellcode' ?>
        </a>

<?php /* Auf der Anzeigeseite kein Sprachwechsel: Ein gewöhnlicher Link würde
         das Fragment verlieren - und damit den Schlüssel. Der Empfänger
         stünde dann vor einem unvollständigen Link. */ ?>
<?php if ($meta->bodyClass !== 'page--reveal'): ?>
        <a class="site-footer__link site-footer__sprache"
           href="<?= $h($englisch ? $deutscherWeg : $englischerWeg) ?>"
           lang="<?= $englisch ? 'de' : 'en' ?>"
           hreflang="<?= $englisch ? 'de' : 'en' ?>"><?= $englisch ? 'Deutsch' : 'English' ?></a>
<?php endif; ?>
    </nav>

    <p class="site-footer__credit">
        powered by <a class="site-footer__link" href="https://pixagentur.com" rel="noopener noreferrer">pixagentur.com</a>
    </p>
</footer>

</body>
</html>
