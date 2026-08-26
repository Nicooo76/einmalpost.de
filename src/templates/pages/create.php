<?php
/**
 * Startseite: Formular, Erklärung, FAQ.
 *
 * Fasst die Datenbank nicht an.
 *
 * @var string $nonceAttribut Bereits maskierter Nonce.
 */
declare(strict_types=1);
?>
<section class="hero">
    <h1 class="hero__title">Passwörter und vertrauliche Daten sicher weitergeben</h1>

    <p class="hero__lead">Ein Text, ein Link, ein einziger Abruf. Verschlüsselt wird in Ihrem
    Browser — der Server bekommt den Inhalt nie zu sehen. Danach ist er weg.</p>

    <ul class="badges">
        <li class="badge">EINMAL LESBAR</li>
        <li class="badge">IM BROWSER VERSCHLÜSSELT</li>
        <li class="badge">KEINE ANMELDUNG</li>
    </ul>
</section>

<noscript>
    <p class="notice notice--warning"><strong>Ohne JavaScript geht es nicht.</strong> Die
    Verschlüsselung findet in Ihrem Browser statt und nicht auf unserem Server — genau
    deshalb können wir Ihre Texte nicht lesen. Ohne JavaScript gäbe es nichts zu
    verschlüsseln.</p>
</noscript>

<section class="card card--form">
    <form class="form" id="formular">
        <label class="field">
            <span class="field__label">TEXT</span>
            <textarea class="field__input field__input--area" id="geheimnis"
                      autocomplete="off" spellcheck="false"
                      placeholder="Passwort, Zugangsdaten, eine kurze Nachricht …"></textarea>
        </label>

        <label class="field field--inline">
            <span class="field__label">GÜLTIG FÜR</span>
            <select class="field__input field__input--select" id="ttl">
                <option value="3600">1 Stunde</option>
                <option value="86400" selected>1 Tag</option>
                <option value="604800">7 Tage</option>
            </select>
        </label>

        <p class="form__actions">
            <button class="btn btn--primary" type="submit" id="absenden">LINK ERZEUGEN</button>
        </p>
    </form>

    <section class="result" id="ergebnis" hidden>
        <h2 class="result__title">IHR LINK IST BEREIT</h2>

        <pre class="code code--link" id="link"></pre>

        <p class="result__actions">
            <button class="btn btn--primary" type="button" id="kopieren">KOPIEREN</button>
        </p>

        <p class="note">Der Link enthält den Schlüssel. Er liegt jetzt in Ihrer Zwischenablage
        und wandert von dort in Ihr Chat- oder Mailprogramm. Behandeln Sie ihn so vertraulich
        wie den Inhalt selbst.</p>

        <p class="note">Dieser Link steht nur hier. Er lässt sich nicht wiederherstellen.</p>
    </section>

    <p class="notice notice--error" id="fehler" hidden></p>
</section>

<section class="steps">
    <h2 class="steps__title">So funktioniert einmalpost</h2>

    <ol class="steps__list">
        <li class="step">
            <h3 class="step__title">Text eingeben</h3>
            <p class="step__text">Ihr Browser erzeugt einen Schlüssel und verschlüsselt den Text
            damit. Beides geschieht auf Ihrem Gerät, bevor etwas gesendet wird.</p>
        </li>
        <li class="step">
            <h3 class="step__title">Link weitergeben</h3>
            <p class="step__text">Der Schlüssel steht hinter dem Rautezeichen des Links. Diesen
            Teil senden Browser grundsätzlich nicht an Server; wir bekommen ihn nie zu sehen.</p>
        </li>
        <li class="step">
            <h3 class="step__title">Einmal lesen</h3>
            <p class="step__text">Wer den Link öffnet und auf Anzeigen drückt, sieht den Inhalt
            ein einziges Mal. Im selben Moment löscht der Server ihn.</p>
        </li>
    </ol>
</section>

<section class="prose">
    <h2 class="prose__title">Warum ein Passwort nichts in einer Chatnachricht verloren hat</h2>

    <p>Zugangsdaten werden selten mit Absicht weitergegeben. Sie rutschen nebenbei in eine
    Nachricht, weil es gerade schnell gehen muss. Dort bleiben sie: im Verlauf des Chats, in
    der Sicherung des Mailkontos, im Postfach eines Kollegen, der die Nachricht weitergeleitet
    hat. Wer Monate später Zugriff auf eines dieser Postfächer bekommt, findet das Passwort
    immer noch.</p>

    <p>einmalpost dreht das um: Der Inhalt liegt nicht in der Nachricht, sondern hinter einem
    Link, der sich beim ersten Abruf selbst zerstört. Was in Chat und Mail zurückbleibt, ist
    ein Link, der ins Leere führt. Und weil die Verschlüsselung in Ihrem Browser stattfindet,
    gibt es auch bei uns nichts zu holen: Auf dem Server liegt ein Schlüsseltext ohne
    Schlüssel.</p>

    <p>Das ersetzt keinen Passwortmanager für dauerhafte Zugänge. Aber für den einen Zugang,
    den Sie jetzt weitergeben müssen, ist es der kürzere Weg: an eine Kollegin, an eine
    Kundin, an den Nachbarn, der das WLAN braucht.</p>
</section>

<section class="faq" id="faq">
    <h2 class="faq__title">Häufige Fragen</h2>

    <details class="faq__item">
        <summary class="faq__question">Kann der Server meine Nachricht lesen?</summary>
        <div class="faq__answer">
            <p>Nein. Der Text wird in Ihrem Browser verschlüsselt, bevor er den Rechner
            verlässt. Der Schlüssel steht nur im Teil des Links hinter dem Rautezeichen und
            wird von Browsern grundsätzlich nicht an Server übertragen. Auf dem Server liegt
            ein Schlüsseltext, den er selbst nicht lesen kann.</p>
        </div>
    </details>

    <details class="faq__item">
        <summary class="faq__question">Brauche ich ein Konto?</summary>
        <div class="faq__answer">
            <p>Nein. Es gibt keine Anmeldung, keine Registrierung und keine E-Mail-Adresse.
            Sie schreiben einen Text und bekommen einen Link.</p>
        </div>
    </details>

    <details class="faq__item">
        <summary class="faq__question">Kann ich einmalpost vertrauen?</summary>
        <div class="faq__answer">
            <p>Nur begrenzt — und das sagen wir lieber offen, als es zu verschweigen.</p>

            <p>Die Verschlüsselung läuft in Ihrem Browser, aber das JavaScript dafür kommt von
            unserem Server. Ein manipulierter Server könnte Code ausliefern, der den Schlüssel
            zusätzlich mitschickt, und Sie würden es nicht bemerken. Das gilt für jeden Dienst
            dieser Art, auch für die bekannten.</p>

            <p>Was Sie dagegen tun können: Der
            <a class="link" href="https://github.com/Nicooo76/einmalpost.de" rel="noopener noreferrer">Quellcode liegt offen</a>.
            Sie können vergleichen, was Ihnen ausgeliefert wird, und einmalpost auf Ihrem
            eigenen Server betreiben. Beweisen können wir es Ihnen nicht.</p>
        </div>
    </details>

    <details class="faq__item">
        <summary class="faq__question">Was ist, wenn jemand den Link abfängt?</summary>
        <div class="faq__answer">
            <p>Dann hat derjenige alles. Der Link enthält den Schlüssel — wer ihn vollständig
            mitliest, kann den Inhalt öffnen. Einmal, wie jeder andere auch.</p>

            <p>Verschicken Sie den Link deshalb möglichst nicht über denselben Kanal wie den
            Hinweis, worum es geht. Und wenn Ihr Empfänger den Link öffnet und der Inhalt
            bereits weg ist, wissen Sie etwas Nützliches: Jemand war vorher da.</p>
        </div>
    </details>

    <details class="faq__item">
        <summary class="faq__question">Bleibt der Link in meinem Browserverlauf?</summary>
        <div class="faq__answer">
            <p>Ja. Der Inhalt ist nach dem Anzeigen gelöscht. Die Adresse mit dem Schlüssel
            steht aber weiterhin im Browserverlauf, bei Ihnen und beim Empfänger, und
            meistens auch in der Nachricht, mit der Sie ihn verschickt haben.</p>

            <p>Sobald der Inhalt abgerufen wurde, nützt der Schlüssel niemandem mehr. Solange
            er es nicht ist, ist der Link genauso vertraulich wie der Inhalt.</p>
        </div>
    </details>
</section>

<script nonce="<?= $nonceAttribut ?>" src="/assets/krypto.js"></script>
<script nonce="<?= $nonceAttribut ?>" src="/assets/create.js"></script>
