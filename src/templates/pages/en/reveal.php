<?php
/**
 * Reveal page, English. Same structure and identifiers as the German
 * version - the scripts are shared.
 *
 * This page does not touch the database. It serves a shell with a button.
 * That is the protection against link preview bots: Slack, Teams and
 * Microsoft Safe Links fetch links automatically and would otherwise burn
 * the content before the recipient sees it. Those bots run no JavaScript
 * and send no POST.
 *
 * @var string $nonceAttribut Already escaped nonce.
 */
declare(strict_types=1);
?>
<noscript>
    <p class="notice notice--warning"><strong>This does not work without JavaScript.</strong>
    Decryption happens in your browser, not on our server — which is exactly why we cannot
    read what you receive. Without JavaScript there would be nothing to decrypt.</p>
</noscript>

<section class="card state state--confirm" id="bestaetigung">
    <h1 class="state__title">SOMETHING CONFIDENTIAL IS WAITING HERE</h1>

    <p class="state__text">When you press Reveal, the content is shown once and deleted in the
    same moment. After that there is no copy left — not with us either.</p>

    <p class="notice notice--warning">Be ready to copy or save it. There is no second
    attempt.</p>

    <p class="state__actions">
        <button class="btn btn--primary" type="button" id="anzeigen">REVEAL</button>
        <button class="btn btn--secondary" type="button" id="nurKopieren">COPY WITHOUT SHOWING</button>
    </p>

    <p class="note">This page has not retrieved the content yet. Preview features in chat and
    mail apps therefore cannot consume it by accident.</p>
</section>

<section class="card state state--passphrase" id="passphraseAbfrage" hidden>
    <h1 class="state__title">THIS ONE NEEDS A PASSPHRASE</h1>

    <p class="state__text">The sender protected this content with an additional passphrase.
    Without it, the content cannot be opened — not by us either.</p>

    <label class="field">
        <span class="field__label">PASSPHRASE</span>
        <input class="field__input" type="password" id="passphraseEingabe"
               autocomplete="off" spellcheck="false">
    </label>

    <p class="notice notice--warning"><strong>A failed attempt consumes the content.</strong>
    We retrieve it in order to check, and that deletes it — even if the passphrase turns out
    to be wrong. If in doubt, ask the sender before continuing.</p>

    <p class="state__actions">
        <button class="btn btn--primary" type="button" id="passphraseAbsenden">OPEN</button>
    </p>
</section>

<p class="loading" id="laedt" hidden>Decrypting …</p>

<section class="card state state--revealed" id="ergebnis" hidden>
    <p class="state__status" id="statuszeile">SHOWN AND DELETED</p>

    <pre class="code code--secret" id="inhalt"></pre>

    <div class="datei" id="dateiErgebnis" hidden>
        <p class="datei__name" id="dateiName"></p>
        <p class="state__actions">
            <a class="btn btn--primary" id="dateiLaden" download>DOWNLOAD</a>
        </p>
        <p class="note">The file was decrypted in your browser. It was never transmitted
        unencrypted.</p>
    </div>

    <p class="state__actions" id="kopierZeile">
        <button class="btn btn--primary" type="button" id="kopieren">COPY</button>
    </p>

    <p class="note">The text is now in your clipboard. Other applications, clipboard histories
    and Apple's device sync can read it there. Copy something else once you are done.</p>

    <p class="note">This content is already deleted on our side. The link, however, remains in
    your browser history — remove it if others have access to this device.</p>
</section>

<section class="card state state--copied" id="nurKopiertFertig" hidden>
    <p class="state__status">COPIED TO CLIPBOARD AND DELETED</p>

    <p class="state__text">The content was not displayed on screen.</p>

    <p class="note">It is now in your clipboard. Other applications, clipboard histories and
    Apple's device sync can read it there. Copy something else once you are done.</p>

    <p class="note">This content is already deleted on our side. The link, however, remains in
    your browser history — remove it if others have access to this device.</p>
</section>

<section class="card state state--incomplete" id="unvollstaendig" hidden>
    <h1 class="state__title">THIS LINK IS INCOMPLETE</h1>

    <p class="state__text">The part after the <code>#</code> is missing. Without it the content
    cannot be opened. Many chat and mail apps shorten long addresses when displaying them —
    copy the link again, in full, from the message you received.</p>

    <p class="notice notice--ok">The content is still there. We have not retrieved it.</p>

    <p class="state__actions">
        <button class="btn btn--primary" type="button" id="erneut">TRY AGAIN</button>
    </p>
</section>

<section class="card state state--failed" id="fehlgeschlagen" hidden>
    <h1 class="state__title">THIS CANNOT BE OPENED</h1>

    <p class="state__text" id="grundSchluessel">The key in the link does not match this
    content.</p>

    <p class="state__text" id="grundPassphrase" hidden>The passphrase does not match. Check
    upper and lower case — and ask the sender if in doubt.</p>

    <p class="notice notice--warning"><strong>The content was deleted on opening
    regardless.</strong> Ask the sender for a new link.</p>
</section>

<?php /* One text for all three cases: never existed, expired, already
         retrieved. The server does not tell them apart, and neither does
         this page. */ ?>
<section class="card state state--gone" id="fortgeschrieben" hidden>
    <h1 class="state__title">THIS NO LONGER EXISTS</h1>

    <p class="state__text">It has already been retrieved, or it expired, or the link is wrong.
    Which of the three, we cannot say — we keep nothing that would tell them apart.</p>

    <p class="state__text">Ask the sender for a new link.</p>
</section>

<section class="card state state--throttled" id="zuVieleAnfragen" hidden>
    <h1 class="state__title">TOO MANY REQUESTS</h1>

    <p class="state__text">A lot of links were created from your connection in a short time.
    Please try again in an hour.</p>
</section>

<p class="notice notice--error" id="fehler" hidden></p>

<p class="state__more"><a class="link" href="/en">+ WRITE YOUR OWN</a></p>

<script nonce="<?= $nonceAttribut ?>" src="/assets/krypto.js"></script>
<script nonce="<?= $nonceAttribut ?>" src="/assets/reveal.js"></script>
