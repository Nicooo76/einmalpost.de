<?php
/**
 * Start page, English. Same structure and same identifiers as the German
 * version - the scripts are shared and must find the same elements.
 *
 * @var string $nonceAttribut Already escaped nonce.
 */
declare(strict_types=1);
?>
<section class="hero">
    <h1 class="hero__title">Share passwords and confidential data safely</h1>

    <p class="hero__lead">One text or file, one link, a single retrieval. Encryption happens
    in your browser — the server never sees the content. After that it is gone.</p>

    <ul class="badges">
        <li class="badge">READ ONCE</li>
        <li class="badge">ENCRYPTED IN YOUR BROWSER</li>
        <li class="badge">NO SIGN-UP</li>
    </ul>
</section>

<noscript>
    <p class="notice notice--warning"><strong>This does not work without JavaScript.</strong>
    Encryption happens in your browser, not on our server — which is exactly why we cannot
    read what you send. Without JavaScript there would be nothing to encrypt.</p>
</noscript>

<section class="card card--form">
    <form class="form" id="formular">
        <label class="field">
            <span class="field__label">TEXT</span>
            <textarea class="field__input field__input--area" id="geheimnis"
                      autocomplete="off" spellcheck="false"
                      placeholder="A password, credentials, a short message …"></textarea>
        </label>

        <div class="field">
            <span class="field__label">OR A FILE</span>
            <input class="field__datei" type="file" id="datei">
            <p class="note" id="dateiInfo" hidden></p>
            <p class="note">Up to 16 MB. A file is encrypted just like a text — we see neither
            its contents nor its name.</p>
        </div>

        <label class="field">
            <span class="field__label">PASSPHRASE (OPTIONAL)</span>
            <input class="field__input" type="password" id="passphrase"
                   autocomplete="new-password" spellcheck="false"
                   placeholder="Leave empty if the link alone is enough">
            <p class="note">With a passphrase the link alone is no longer enough. Share it
            through a different channel than the link — by phone, in person. <strong>If it is
            forgotten, the content is unreachable</strong>, for us as well.</p>
        </label>

        <label class="field field--inline">
            <span class="field__label">VALID FOR</span>
            <select class="field__input field__input--select" id="ttl">
                <option value="3600">1 hour</option>
                <option value="86400" selected>1 day</option>
                <option value="604800">7 days</option>
            </select>
        </label>

        <p class="form__actions">
            <button class="btn btn--primary" type="submit" id="absenden">CREATE LINK</button>
        </p>
    </form>

    <section class="result" id="ergebnis" hidden>
        <h2 class="result__title">YOUR LINK IS READY</h2>

        <pre class="code code--link" id="link"></pre>

        <p class="result__actions">
            <button class="btn btn--primary" type="button" id="kopieren">COPY</button>
            <button class="btn btn--secondary" type="button" id="qrZeigen">AS QR CODE</button>
        </p>

        <figure class="qr" id="qrBereich" hidden>
            <div class="qr__flaeche" id="qrFlaeche"></div>
            <figcaption class="note">For scanning with a second device. The key is part of the
            image — treat it like the link itself.</figcaption>
        </figure>

        <p class="notice notice--warning" id="passphraseHinweis" hidden>This link also needs
        the passphrase. Share it through a different channel than the link.</p>

        <p class="note">The link contains the key. It is now in your clipboard and will travel
        from there into your chat or mail app — treat it as confidentially as the content
        itself.</p>

        <p class="note">This link exists only here. It cannot be recovered.</p>
    </section>

    <p class="notice notice--error" id="fehler" hidden></p>
</section>

<section class="steps">
    <h2 class="steps__title">How einmalpost works</h2>

    <ol class="steps__list">
        <li class="step">
            <h3 class="step__title">Enter your text</h3>
            <p class="step__text">Your browser creates a key and encrypts the text with it.
            Both happen on your device, before anything is sent.</p>
        </li>
        <li class="step">
            <h3 class="step__title">Share the link</h3>
            <p class="step__text">The key sits after the hash mark of the link. Browsers do
            not send that part to servers — we never get to see it.</p>
        </li>
        <li class="step">
            <h3 class="step__title">Read once</h3>
            <p class="step__text">Whoever opens the link and presses Reveal sees the content a
            single time. In the same moment, the server deletes it.</p>
        </li>
    </ol>
</section>

<section class="prose">
    <h2 class="prose__title">Why a password has no business being in a chat message</h2>

    <p>Credentials are rarely shared deliberately — they slip into a message because things
    need to move fast. And there they stay: in the chat history, in the mailbox backup, in the
    inbox of a colleague who forwarded it. Anyone who gains access to one of those months
    later still finds the password.</p>

    <p>einmalpost turns that around: the content does not live in the message but behind a
    link that destroys itself on first retrieval. What remains in chat and mail is a link that
    leads nowhere. And because encryption happens in your browser, there is nothing to take
    from us either: the server holds ciphertext without a key.</p>

    <p>This does not replace a password manager for lasting access. For the one credential you
    need to hand over right now, it is the shorter path.</p>
</section>

<section class="faq" id="faq">
    <h2 class="faq__title">Frequently asked questions</h2>

    <details class="faq__item">
        <summary class="faq__question">Can the server read my message?</summary>
        <div class="faq__answer">
            <p>No. Your text is encrypted in your browser before it leaves your device. The key
            sits only in the part of the link after the hash mark, and browsers do not send
            that part to servers. What the server holds is ciphertext it cannot read.</p>
        </div>
    </details>

    <details class="faq__item">
        <summary class="faq__question">Do I need an account?</summary>
        <div class="faq__answer">
            <p>No. There is no sign-up, no registration and no email address. You write
            something and you get a link.</p>
        </div>
    </details>

    <details class="faq__item">
        <summary class="faq__question">Can I trust einmalpost?</summary>
        <div class="faq__answer">
            <p>Only so far — and we would rather say that than hide it.</p>

            <p>The encryption runs in your browser, but the JavaScript doing it comes from our
            server. A tampered server could serve code that sends the key along, and you would
            not notice. That is true of every service of this kind, including the well-known
            ones.</p>

            <p>What you can do about it: the
            <a class="link" href="https://github.com/Nicooo76/einmalpost.de" rel="noopener noreferrer">source
            code is open</a>. You can compare what you are served, and you can run einmalpost
            on your own server. What we cannot do is prove it to you.</p>
        </div>
    </details>

    <details class="faq__item">
        <summary class="faq__question">What if someone intercepts the link?</summary>
        <div class="faq__answer">
            <p>Then they have everything. The link contains the key — anyone who reads it in
            full can open the content. Once, like anyone else.</p>

            <p>So avoid sending the link through the same channel as the note explaining what
            it is. Use a passphrase where that matters. And if your recipient opens the link
            and the content is already gone, you have learned something useful: somebody was
            there first.</p>
        </div>
    </details>

    <details class="faq__item">
        <summary class="faq__question">Does the link stay in my browser history?</summary>
        <div class="faq__answer">
            <p>Yes. The content is deleted after being shown, but the address including the key
            remains in the browser history — yours and the recipient's — and usually in the
            message you sent it with.</p>

            <p>Once the content has been retrieved, the key is useless to anyone. Until then,
            the link is exactly as confidential as the content.</p>
        </div>
    </details>
</section>

<script nonce="<?= $nonceAttribut ?>" src="/assets/krypto.js"></script>
<script nonce="<?= $nonceAttribut ?>" src="/assets/qr.js"></script>
<script nonce="<?= $nonceAttribut ?>" src="/assets/create.js"></script>
