<?php
/**
 * Security page, English.
 *
 * The honest account, including the limit that cannot be closed technically.
 * Hiding a weakness costs more when it surfaces than it ever gained.
 */
declare(strict_types=1);
?>
<article class="prose">
    <h1 class="prose__title">Security</h1>

    <p class="prose__lead">What the server sees, what it does not — and the one limit we
    cannot close.</p>

    <h2>How the encryption works</h2>

    <p>When you press "Create link", the following happens in your browser before anything is
    sent:</p>

    <ol>
        <li>Your browser generates a random 256-bit key.</li>
        <li>It encrypts your content with <strong>AES-256-GCM</strong>. That mode does not
        only encrypt, it detects later tampering: a single flipped bit makes decryption fail
        instead of producing nonsense.</li>
        <li>Before encryption, the content is padded to a multiple of 256 bytes. Otherwise the
        stored length alone would reveal whether it holds a short password or a long
        certificate.</li>
        <li>Only the ciphertext is sent to the server. <strong>The key stays in the
        browser</strong> and is appended to the link, after the hash mark.</li>
    </ol>

    <p>The part after the hash mark is called the fragment. Browsers <strong>do not send
    it</strong> to servers; it exists for navigation within a page. That is why the key is in
    the link and still never reaches us.</p>

    <h2>What a passphrase adds</h2>

    <p>If you set one, the actual key is derived from <em>both</em> parts: the random key in
    the link and your passphrase, run through PBKDF2 with 600,000 rounds. Whoever intercepts
    the link has nothing without the passphrase; whoever guesses the passphrase has nothing
    without the link.</p>

    <p>We ask for the passphrase <strong>before</strong> retrieving anything. Retrieving
    consumes the content — asking afterwards would destroy it on a typo.</p>

    <h2>What the server holds</h2>

    <p>Three values per entry: a random identifier, the ciphertext, and an expiry time. No
    creation time, no IP address, no browser identifier, no retrieval counter. Web server
    access logs are switched off for this domain.</p>

    <p>The test we hold every decision against: <strong>if someone seizes the database and the
    entire server, they must find nothing readable.</strong></p>

    <h2>Exactly once — even for simultaneous requests</h2>

    <p>Reading and deleting happen in <em>one</em> database statement. A read followed by a
    delete would leave a window of milliseconds in which two simultaneous requests both
    receive the content. That is not hypothetical: mail gateways check links repeatedly and in
    parallel.</p>

    <h2>Why there is a button between link and content</h2>

    <p>Chat and mail applications fetch links automatically to build a preview. If the content
    were served on page load, it would be consumed before the recipient ever saw it. So only a
    button press retrieves it — preview features run no JavaScript and send no such
    request.</p>

    <h2>The limit we cannot close</h2>

    <p>Encryption runs in your browser, but <strong>the JavaScript doing it comes from our
    server</strong>. A tampered server — by us, by an attacker, or by order — could serve code
    that sends the key along. You would not notice while using it.</p>

    <p>This is true of every service built this way, including the well-known ones. Anyone
    claiming otherwise either has not thought it through or is counting on you not to.</p>

    <p>What helps:</p>

    <ul>
        <li>You can compare what you are served. The
        <a class="link" href="https://github.com/Nicooo76/einmalpost.de" rel="noopener noreferrer">source
        code is open</a>, unminified and free of third-party libraries.</li>
        <li>You can run einmalpost on your own server. Then the question of trust is yours
        alone.</li>
        <li>For content where that is not enough, use a method where both sides already share
        a key.</li>
    </ul>

    <h2 id="quellcode">Source code</h2>

    <p>The full source code is public:
    <a class="link" href="https://github.com/Nicooo76/einmalpost.de" rel="noopener noreferrer">github.com/Nicooo76/einmalpost.de</a></p>

    <p>It contains no credentials, no build step and no third-party libraries. What your
    browser executes is there in plain text and can be compared line by line with what this
    site serves you.</p>

    <p>Not included is the visual design — colours, fonts and images. Anyone cloning the
    project gets a fully usable service in plain grey. Everything that affects behaviour is
    visible.</p>

    <h2>What we do not promise</h2>

    <p>We do not promise that deleted content is physically unrecoverable — databases and file
    systems release storage lazily. We do not promise that an attacker with access to your
    device or the recipient's finds nothing: the link sits in the browser history, the content
    possibly in the clipboard. And we do not promise anonymity towards your network
    provider.</p>

    <h2>Reporting a vulnerability</h2>

    <p>Please write to
    <a class="link" href="mailto:info@pixagentur.com">info@pixagentur.com</a>. Report it
    before publishing — and expect an answer, not a lawyer.</p>
</article>
