<?php
/**
 * Anzeigeseite.
 *
 * Diese Seite fasst die Datenbank nicht an. Sie liefert nur ein Gerüst mit
 * einem Knopf. Das ist der Schutz gegen Vorschau-Bots: Slack, Teams und
 * Microsoft Safe Links rufen Links automatisch ab und würden das Geheimnis
 * sonst verbrennen, bevor der Empfänger es sieht. Diese Bots führen kein
 * JavaScript aus und senden kein POST.
 *
 * Hier bleibt es karg: Kein Werbetext, keine FAQ, keine zusätzlichen
 * Skripte. Auf dieser Seite steht der Klartext - jedes weitere Element ist
 * zusätzliche Angriffsfläche.
 *
 * Alle Zustände stehen fertig im HTML und werden nur ein- und ausgeblendet.
 * So muss kein Skript Auszeichnung erzeugen; innerHTML ist im ganzen Projekt
 * verboten.
 *
 * @var string $nonceAttribut Bereits maskierter Nonce.
 */
declare(strict_types=1);
?>
<noscript>
    <p class="notice notice--warning"><strong>Ohne JavaScript geht es nicht.</strong> Die
    Entschlüsselung findet in Ihrem Browser statt und nicht auf unserem Server — genau
    deshalb können wir Ihre Texte nicht lesen. Ohne JavaScript gäbe es nichts zu
    entschlüsseln.</p>
</noscript>

<section class="card state state--confirm" id="bestaetigung">
    <h1 class="state__title">HIER LIEGT EIN VERTRAULICHER TEXT</h1>

    <p class="state__text">Wenn Sie auf Anzeigen drücken, wird der Text einmal dargestellt und
    im selben Moment gelöscht. Danach gibt es keine Kopie mehr, auch nicht bei uns.</p>

    <p class="notice notice--warning">Halten Sie sich bereit, den Text zu kopieren. Einen
    zweiten Versuch gibt es nicht.</p>

    <p class="state__actions">
        <button class="btn btn--primary" type="button" id="anzeigen">ANZEIGEN</button>
        <button class="btn btn--secondary" type="button" id="nurKopieren">KOPIEREN, OHNE ANZUZEIGEN</button>
    </p>

    <p class="note">Diese Seite hat den Inhalt noch nicht abgerufen. Vorschaufunktionen von
    Chat- und Mailprogrammen können ihn deshalb nicht versehentlich verbrauchen.</p>
</section>

<p class="loading" id="laedt" hidden>Wird entschlüsselt …</p>

<section class="card state state--revealed" id="ergebnis" hidden>
    <p class="state__status" id="statuszeile">ANGEZEIGT UND GELÖSCHT</p>

    <pre class="code code--secret" id="inhalt"></pre>

    <p class="state__actions">
        <button class="btn btn--primary" type="button" id="kopieren">KOPIEREN</button>
    </p>

    <p class="note">Der Text liegt jetzt in Ihrer Zwischenablage. Andere Programme,
    Zwischenablage-Verläufe und die Gerätesynchronisierung von Apple können ihn dort lesen.
    Kopieren Sie etwas anderes, sobald Sie fertig sind.</p>

    <p class="note">Dieser Text ist bei uns bereits gelöscht. Der Link steht aber noch in
    Ihrem Browserverlauf — entfernen Sie ihn, wenn andere Zugriff auf dieses Gerät haben.</p>
</section>

<section class="card state state--copied" id="nurKopiertFertig" hidden>
    <p class="state__status">IN DIE ZWISCHENABLAGE KOPIERT UND GELÖSCHT</p>

    <p class="state__text">Der Text wurde nicht auf dem Bildschirm dargestellt.</p>

    <p class="note">Er liegt jetzt in Ihrer Zwischenablage. Andere Programme,
    Zwischenablage-Verläufe und die Gerätesynchronisierung von Apple können ihn dort lesen.
    Kopieren Sie etwas anderes, sobald Sie fertig sind.</p>

    <p class="note">Dieser Text ist bei uns bereits gelöscht. Der Link steht aber noch in
    Ihrem Browserverlauf — entfernen Sie ihn, wenn andere Zugriff auf dieses Gerät haben.</p>
</section>

<section class="card state state--incomplete" id="unvollstaendig" hidden>
    <h1 class="state__title">DIESER LINK IST UNVOLLSTÄNDIG</h1>

    <p class="state__text">Im Link fehlt der Teil hinter dem <code>#</code>. Ohne ihn lässt
    sich der Inhalt nicht öffnen. Viele Chat- und Mailprogramme kürzen lange Adressen beim
    Anzeigen. Kopieren Sie den Link noch einmal vollständig aus der Nachricht, die Sie
    bekommen haben.</p>

    <p class="notice notice--ok">Der Inhalt ist noch da. Wir haben ihn nicht abgerufen.</p>

    <p class="state__actions">
        <button class="btn btn--primary" type="button" id="erneut">ERNEUT VERSUCHEN</button>
    </p>
</section>

<section class="card state state--failed" id="fehlgeschlagen" hidden>
    <h1 class="state__title">DER TEXT LÄSST SICH NICHT ÖFFNEN</h1>

    <p class="state__text">Der Schlüssel im Link passt nicht zu diesem Inhalt.</p>

    <p class="notice notice--warning"><strong>Der Inhalt wurde beim Öffnen trotzdem
    gelöscht.</strong> Bitten Sie den Absender um einen neuen Link.</p>
</section>

<?php /* Ein Text für alle drei Fälle: gibt es nicht, abgelaufen, schon
         abgerufen. Der Server unterscheidet sie nicht, und diese Seite
         tut es auch nicht. */ ?>
<section class="card state state--gone" id="fortgeschrieben" hidden>
    <h1 class="state__title">DIESEN TEXT GIBT ES NICHT MEHR</h1>

    <p class="state__text">Er wurde bereits abgerufen, ist abgelaufen, oder der Link stimmt
    nicht. Welcher der drei Fälle es ist, können wir nicht sagen: Wir bewahren nichts auf,
    woran man es erkennen könnte.</p>

    <p class="state__text">Bitten Sie den Absender um einen neuen Link.</p>
</section>

<section class="card state state--throttled" id="zuVieleAnfragen" hidden>
    <h1 class="state__title">ZU VIELE ANFRAGEN</h1>

    <p class="state__text">Von Ihrem Anschluss wurden in kurzer Zeit sehr viele Links erzeugt.
    Versuchen Sie es in einer Stunde noch einmal.</p>
</section>

<p class="notice notice--error" id="fehler" hidden></p>

<p class="state__more"><a class="link" href="/">+ EIGENEN TEXT SCHREIBEN</a></p>

<script nonce="<?= $nonceAttribut ?>" src="/assets/krypto.js"></script>
<script nonce="<?= $nonceAttribut ?>" src="/assets/reveal.js"></script>
