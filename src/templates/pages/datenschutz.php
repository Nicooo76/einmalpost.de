<?php
/**
 * Datenschutzerklärung.
 *
 * Der technische Teil beschreibt, was tatsächlich gespeichert wird - er ist
 * aus dem Schema und dem Betrieb abgeleitet, nicht aus einer Vorlage
 * abgeschrieben. Die Angaben zum Verantwortlichen kommen vom Betreiber.
 */
declare(strict_types=1);
?>
<article class="prose">
    <h1 class="prose__title">Datenschutz</h1>

    <h2>Verantwortlicher</h2>

    <p>
        Sven Gauditz<br>
        PixAgentur, Webdesign &amp; digitale Lösungen<br>
        Ringstraße 3<br>
        24321 Behrensdorf<br>
        Deutschland<br>
        E-Mail: <a class="link" href="mailto:info@pixagentur.com">info@pixagentur.com</a>
    </p>

    <h2>Was gespeichert wird</h2>

    <p>Für jeden erzeugten Link legen wir genau drei Angaben ab:</p>

    <ul>
        <li><strong>Eine zufällige Kennung.</strong> 16 Byte aus dem Zufallsgenerator des
        Servers. Sie steht im Link und lässt keinen Rückschluss auf Absender oder Inhalt zu.</li>

        <li><strong>Den Schlüsseltext.</strong> Das Ergebnis der Verschlüsselung, die in Ihrem
        Browser stattgefunden hat. <strong>Wir können ihn nicht lesen</strong> — der Schlüssel
        dazu hat unseren Server nie erreicht. Die Länge ist auf Blöcke von 256 Byte
        aufgerundet, damit die gespeicherte Größe nicht verrät, wie lang Ihr Text war.</li>

        <li><strong>Einen Ablaufzeitpunkt.</strong> Je nach Ihrer Wahl eine Stunde, ein Tag
        oder sieben Tage in der Zukunft.</li>
    </ul>

    <p>Beim ersten Abruf werden diese Angaben in derselben Datenbankanweisung gelesen und
    gelöscht. Danach existieren sie nicht mehr. Abgelaufene Einträge entfernt ein Aufräumlauf
    regelmäßig, unabhängig davon, ob sie je abgerufen wurden.</p>

    <h2>Was ausdrücklich nicht gespeichert wird</h2>

    <ul>
        <li><strong>Keine IP-Adresse im Klartext</strong> — weder zum Erstellen noch zum
        Abrufen.</li>
        <li><strong>Kein Erstellungszeitpunkt.</strong> Gespeichert wird nur, wann ein Eintrag
        abläuft, nicht wann er entstanden ist.</li>
        <li><strong>Kein Browserkennzeichen</strong> (User-Agent), <strong>keine
        Verweisadresse</strong> (Referrer), <strong>kein Aufrufzähler</strong>.</li>
        <li><strong>Keine Zugriffsprotokolle des Webservers.</strong> Sie sind für diese
        Domain abgeschaltet.</li>
        <li><strong>Keine Cookies</strong>, keine Analyse, keine Einbindungen von fremden
        Servern. Es gibt nichts einzuwilligen.</li>
        <li><strong>Kein Konto</strong>, keine Registrierung, keine E-Mail-Adresse.</li>
    </ul>

    <h2>Schutz gegen Missbrauch</h2>

    <p>Damit niemand den Dienst zum Massenversand missbraucht, begrenzen wir die Anzahl neu
    erzeugter Links je Anschluss und Stunde. Dafür wird <strong>nicht die IP-Adresse
    gespeichert</strong>, sondern ein nicht umkehrbarer Fingerabdruck davon: ein HMAC-SHA256
    mit einem Schlüssel, der täglich wechselt und nur in der Serverkonfiguration liegt.</p>

    <p>Aus diesem Fingerabdruck lässt sich die Adresse nicht zurückrechnen. Nach einem
    Tageswechsel ist der Bezug auch rechnerisch nicht mehr herstellbar. Diese Einträge laufen
    nach <strong>einer Stunde</strong> ab und werden dann gelöscht.</p>

    <p>Rechtsgrundlage ist Artikel 6 Absatz 1 Buchstabe f DSGVO — unser berechtigtes Interesse
    daran, den Dienst betriebsfähig zu halten. Das Interesse an einem funktionierenden Dienst
    überwiegt hier, weil die Verarbeitung so gestaltet ist, dass aus ihr keine Person mehr zu
    bestimmen ist.</p>

    <h2>Rechtsgrundlage für den Dienst selbst</h2>

    <p>Die Speicherung des Schlüsseltextes erfolgt nach Artikel 6 Absatz 1 Buchstabe b DSGVO
    zur Durchführung der von Ihnen gewünschten Leistung: Sie wollen einen Text übertragen, und
    dafür muss er bis zum Abruf irgendwo liegen.</p>

    <h2>Auftragsverarbeiter</h2>

    <p>Diese Website wird bei der <strong>IONOS SE</strong>, Elgendorfer Str. 57,
    56410 Montabaur, Deutschland gehostet. Die Server stehen ausschließlich in Deutschland.
    Mit IONOS besteht ein Vertrag zur Auftragsverarbeitung nach Artikel 28 DSGVO.</p>

    <p>Weitere Auftragsverarbeiter setzen wir nicht ein. Insbesondere gibt es keine
    Analysedienste, kein Content Delivery Network und keine Schriftarten von fremden Servern —
    die verwendeten Schriften liegen auf demselben Server wie die Website. Beim Aufruf dieser
    Seiten wird keine Verbindung zu einem dritten Anbieter hergestellt.</p>

    <h2>Ihre Rechte</h2>

    <p>Sie haben nach der Datenschutz-Grundverordnung das Recht auf Auskunft (Art. 15),
    Berichtigung (Art. 16), Löschung (Art. 17), Einschränkung der Verarbeitung (Art. 18),
    Datenübertragbarkeit (Art. 20) und Widerspruch (Art. 21). Außerdem können Sie sich bei
    einer Aufsichtsbehörde beschweren (Art. 77).</p>

    <p><strong>Eine Einschränkung müssen wir offen benennen:</strong> Wir speichern nichts, was
    mit Ihrer Person verknüpft ist. Deshalb können wir eine Auskunft auch nicht erteilen — wir
    können nicht feststellen, welcher Eintrag zu Ihnen gehört, und wir sind nach Artikel 11
    DSGVO nicht verpflichtet, dafür zusätzliche Daten zu erheben. Sie können jeden Eintrag
    selbst beenden, indem Sie den Link abrufen oder ablaufen lassen.</p>

    <h2>Änderungen</h2>

    <p>Ändert sich die Verarbeitung, ändert sich diese Erklärung. Der jeweils gültige Stand
    steht auf dieser Seite.</p>

    <p class="note">Stand: August 2026.</p>
</article>
