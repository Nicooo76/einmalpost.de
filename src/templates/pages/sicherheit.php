<?php
/**
 * Sicherheitsseite.
 *
 * Die ehrliche Darstellung, einschließlich der Grenze, die sich technisch
 * nicht schließen lässt. Wer eine Schwäche verschweigt, verliert beim
 * Auffliegen mehr, als er durch das Verschweigen gewonnen hat.
 */
declare(strict_types=1);
?>
<article class="prose">
    <h1 class="prose__title">Sicherheit</h1>

    <p class="prose__lead">Was der Server sieht, was er nicht sieht — und die eine Grenze, die
    wir nicht schließen können.</p>

    <h2>Wie die Verschlüsselung abläuft</h2>

    <p>Wenn Sie auf „Link erzeugen" drücken, geschieht Folgendes in Ihrem Browser, bevor
    irgendetwas gesendet wird:</p>

    <ol>
        <li>Ihr Browser erzeugt einen zufälligen Schlüssel mit 256 Bit.</li>
        <li>Er verschlüsselt Ihren Text mit <strong>AES-256-GCM</strong>. Das Verfahren
        verschlüsselt und erkennt nachträgliche Veränderungen: Ein einziges gekipptes Bit
        lässt die Entschlüsselung fehlschlagen, statt Unsinn zu liefern.</li>
        <li>Vor dem Verschlüsseln wird der Text auf ein Vielfaches von 256 Byte aufgefüllt.
        Sonst verriete allein die gespeicherte Länge, ob dort ein kurzes Kennwort oder ein
        langes Zertifikat liegt.</li>
        <li>Nur der Schlüsseltext wird an den Server gesendet. <strong>Der Schlüssel bleibt im
        Browser</strong> und wird an den Link gehängt, hinter das Rautezeichen.</li>
    </ol>

    <p>Der Teil hinter dem Rautezeichen heißt Fragment. Browser senden ihn
    <strong>grundsätzlich nicht</strong> an Server; er dient zur Navigation innerhalb einer
    Seite. Deshalb steht der Schlüssel zwar im Link, erreicht uns aber nie.</p>

    <h2>Was auf dem Server liegt</h2>

    <p>Drei Angaben je Eintrag: eine zufällige Kennung, der Schlüsseltext und ein
    Ablaufzeitpunkt. Kein Erstellungszeitpunkt, keine IP-Adresse, kein Browserkennzeichen,
    kein Aufrufzähler. Die Zugriffsprotokolle des Webservers sind für diese Domain
    abgeschaltet.</p>

    <p>Der Prüfstein, an dem wir jede Entscheidung messen: <strong>Wer die Datenbank und den
    ganzen Server erbeutet, darf nichts Lesbares finden.</strong></p>

    <h2>Genau einmal — auch bei gleichzeitigen Abrufen</h2>

    <p>Gelesen und gelöscht wird in <em>einer</em> Datenbankanweisung. Ein Lesen mit
    anschließendem Löschen hätte dazwischen ein Fenster von Millisekunden, in dem zwei
    gleichzeitige Anfragen beide den Inhalt bekämen. Das ist kein theoretischer Fall:
    Mail-Gateways prüfen Links regelmäßig mehrfach und parallel.</p>

    <h2>Warum ein Knopf zwischen Link und Inhalt steht</h2>

    <p>Chat- und Mailprogramme rufen Links automatisch ab, um eine Vorschau zu erzeugen.
    Würde der Inhalt schon beim Öffnen der Seite ausgeliefert, wäre er verbraucht, bevor der
    Empfänger ihn sieht. Deshalb holt erst der Knopfdruck den Inhalt. Vorschaufunktionen
    führen kein JavaScript aus und senden keine solche Anfrage.</p>

    <h2>Die Grenze, die wir nicht schließen können</h2>

    <p>Die Verschlüsselung läuft in Ihrem Browser, aber <strong>das JavaScript dafür kommt von
    unserem Server</strong>. Ein manipulierter Server könnte Code ausliefern, der den
    Schlüssel zusätzlich mitschickt. Manipuliert durch uns, durch einen Angreifer oder durch
    eine Anordnung. Sie würden es beim Benutzen nicht bemerken.</p>

    <p>Das gilt für jeden Dienst dieser Bauart, auch für die bekannten. Wer etwas anderes
    behauptet, hat entweder nicht nachgedacht oder rechnet damit, dass Sie es nicht tun.</p>

    <p>Was dagegen hilft:</p>

    <ul>
        <li>Sie können vergleichen, was Ihnen ausgeliefert wird. Der
        <a class="link" href="https://github.com/Nicooo76/einmalpost.de" rel="noopener noreferrer">Quellcode liegt offen</a>,
        unverdichtet und ohne fremde Bibliotheken.</li>
        <li>Sie können einmalpost auf Ihrem eigenen Server betreiben. Dann liegt die
        Vertrauensfrage bei Ihnen selbst.</li>
        <li>Für Inhalte, bei denen das nicht reicht, verwenden Sie ein Verfahren, bei dem beide
        Seiten den Schlüssel schon vorher kennen.</li>
    </ul>

    <h2 id="quellcode">Quellcode</h2>

    <p>Der vollständige Quellcode liegt offen:
    <a class="link" href="https://github.com/Nicooo76/einmalpost.de" rel="noopener noreferrer">github.com/Nicooo76/einmalpost.de</a></p>

    <p>Er enthält keine Zugangsdaten, keinen Bauschritt und keine fremden Bibliotheken. Was
    Ihr Browser ausführt, steht dort im Klartext und lässt sich Zeile für Zeile mit dem
    vergleichen, was Ihnen diese Seite ausliefert.</p>

    <p>Nicht enthalten sein wird die Gestaltung: Farben, Schriften und Bildmaterial. Wer das
    Projekt klont, bekommt einen vollständig bedienbaren Dienst in schlichtem Grau. Alles, was
    das Verhalten betrifft, ist einsehbar.</p>

    <h2>Was wir nicht versprechen</h2>

    <p>Wir versprechen nicht, dass ein Inhalt nach dem Löschen physisch nicht mehr
    rekonstruierbar ist; Datenbanken und Dateisysteme geben Speicher verzögert frei. Wir
    versprechen nicht, dass ein Angreifer mit Zugriff auf Ihr Gerät oder das des Empfängers
    nichts findet: Der Link steht im Browserverlauf, der Inhalt möglicherweise in der
    Zwischenablage. Und wir versprechen keine Anonymität gegenüber Ihrem Netzbetreiber.</p>

    <h2>Eine Schwachstelle melden</h2>

    <p>Wenn Sie eine Lücke finden, schreiben Sie an
    <a class="link" href="mailto:info@pixagentur.com">info@pixagentur.com</a>. Melden Sie sie
    bitte, bevor Sie sie veröffentlichen. Und rechnen Sie mit einer Antwort, nicht mit einer
    Abmahnung.</p>
</article>
