<?php

declare(strict_types=1);

namespace Einmalpost\Http;

use Einmalpost\Base64Url;
use Einmalpost\RateLimiter;
use Einmalpost\Sprache;
use Einmalpost\SecretStore;
use Einmalpost\ValidationError;
use Einmalpost\View;
use JsonException;

/**
 * Die vier Endpunkte.
 *
 * Der Router gibt Antworten zurück, statt sie auszugeben. Dadurch lassen sie
 * sich im Test byteweise vergleichen - Voraussetzung für Zusage 8.
 */
final class Router
{
    /**
     * Die eine Antwort für "gibt es nicht", "abgelaufen" und "schon
     * abgerufen". Derselbe Status, derselbe Text, derselbe Codepfad,
     * dieselbe Abfrage. Sonst verrät die Antwort, ob eine ID jemals
     * existiert hat.
     */
    public const NICHT_GEFUNDEN_BODY = '{"fehler":"nicht_gefunden"}';

    public const NICHT_GEFUNDEN_STATUS = 404;

    /**
     * Höchstgröße eines Anfragerumpfs: 25 MB.
     *
     * Ein payload von 17 MiB wird als base64url rund 23,8 MB groß, dazu der
     * JSON-Rahmen.
     */
    public const RUMPF_MAX_BYTES = 26_214_400;

    /**
     * Seiten, die nur Text zeigen.
     *
     * Sprache => Pfad => [Vorlage, Titel, Beschreibung].
     *
     * @var array<string, array<string, array{string, string, string}>>
     */
    private const INHALTSSEITEN = [
        Sprache::DEUTSCH => [
            '/impressum'   => ['impressum', 'Impressum', 'Anbieterkennzeichnung nach § 5 DDG.'],
            '/datenschutz' => [
                'datenschutz',
                'Datenschutz',
                'Was einmalpost speichert und was ausdrücklich nicht: keine IP-Adresse im '
                . 'Klartext, kein Erstellungszeitpunkt, keine Zugriffsprotokolle.',
            ],
            '/sicherheit'  => [
                'sicherheit',
                'Sicherheit',
                'Wie die Verschlüsselung im Browser abläuft, was auf dem Server liegt — und die '
                . 'eine Grenze, die sich nicht schließen lässt.',
            ],
        ],
        // Impressum und Datenschutz gibt es nur auf Deutsch: Sie gelten nach
        // deutschem Recht, und eine Übersetzung wäre eine unverbindliche
        // Zweitfassung. Der Fußbereich verweist auf sie und sagt das dazu.
        Sprache::ENGLISCH => [
            '/security' => [
                'security',
                'Security',
                'How encryption works in your browser, what the server holds — and the one '
                . 'limit that cannot be closed.',
            ],
        ],
    ];

    public function __construct(
        private readonly SecretStore $store,
        private readonly RateLimiter $limiter,
    ) {
    }

    public function dispatch(Request $request): Response
    {
        [$sprache, $pfad] = Sprache::ausPfad($request->path);

        $methode = $request->method === 'HEAD' ? 'GET' : $request->method;

        if ($pfad === '/' || $pfad === '') {
            return $methode === 'GET' ? $this->seiteErstellen($sprache) : $this->methodeNichtErlaubt();
        }

        if (str_starts_with($pfad, '/s/')) {
            return $methode === 'GET' ? $this->seiteAnzeigen($sprache) : $this->methodeNichtErlaubt();
        }

        if (isset(self::INHALTSSEITEN[$sprache][$pfad])) {
            return $methode === 'GET'
                ? $this->inhaltsseite($sprache, $pfad)
                : $this->methodeNichtErlaubt();
        }

        if ($pfad === '/api/create') {
            return $methode === 'POST' ? $this->apiErstellen($request) : $this->methodeNichtErlaubt();
        }

        if ($pfad === '/api/reveal') {
            return $methode === 'POST' ? $this->apiAbrufen($request) : $this->methodeNichtErlaubt();
        }

        return $this->nichtGefunden();
    }

    // ------------------------------------------------------------------
    // Seiten
    // ------------------------------------------------------------------

    private function seiteErstellen(string $sprache): Response
    {
        $nonce = SecurityHeaders::nonce();

        return Response::html(200, View::createPage($nonce, $sprache), SecurityHeaders::forNonce($nonce));
    }

    /**
     * GET /s/{id}
     *
     * Fasst die Datenbank nicht an. Kein Lesen, kein Zählen, kein Verbrauch.
     * Die ID wird nicht einmal ausgewertet - das erledigt der Browser aus
     * der Adresse. Vorschau-Bots können hier nichts verbrennen.
     */
    private function seiteAnzeigen(string $sprache): Response
    {
        $nonce = SecurityHeaders::nonce();

        // Auf /s/* wird nichts indexiert. Nicht über robots.txt: Ein
        // "Disallow: /s/" verhindert die Indexierung nicht zuverlässig und
        // verrät obendrein die Struktur.
        return Response::html(
            200,
            View::revealPage($nonce, $sprache),
            ['X-Robots-Tag' => 'noindex, nofollow'] + SecurityHeaders::forNonce($nonce)
        );
    }

    private function inhaltsseite(string $sprache, string $pfad): Response
    {
        [$vorlage, $titel, $beschreibung] = self::INHALTSSEITEN[$sprache][$pfad];

        $nonce = SecurityHeaders::nonce();

        return Response::html(
            200,
            View::infoPage($vorlage, $nonce, $titel, $beschreibung, $sprache),
            SecurityHeaders::forNonce($nonce)
        );
    }

    // ------------------------------------------------------------------
    // Schnittstellen
    // ------------------------------------------------------------------

    private function apiErstellen(Request $request): Response
    {
        // Zu groß ist etwas anderes als unbrauchbar. Wer 20 MB schickt, soll
        // das erfahren und nicht rätseln - zumal PHP den Rumpf bei
        // Überschreiten von post_max_size stillschweigend verwirft.
        if ($this->istZuGross($request)) {
            return $this->fehler(413, 'zu_gross');
        }

        $daten = $this->jsonRumpf($request);

        if ($daten === null) {
            return $this->fehler(400, 'ungueltige_anfrage');
        }

        if (!$this->limiter->allow($request->clientIp)) {
            return $this->fehler(429, 'zu_viele_anfragen');
        }

        $payloadKodiert = $daten['payload'] ?? null;
        $ttl            = $daten['ttl'] ?? null;

        if (!is_string($payloadKodiert)) {
            return $this->fehler(400, 'ungueltige_anfrage');
        }

        $payload = Base64Url::decode($payloadKodiert);

        if ($payload === null) {
            return $this->fehler(400, 'ungueltige_anfrage');
        }

        if (!is_int($ttl)) {
            return $this->fehler(400, 'ungueltige_anfrage');
        }

        try {
            $id = $this->store->create($payload, $ttl);
        } catch (ValidationError) {
            return $this->fehler(400, 'ungueltige_anfrage');
        }

        return Response::json(201, ['id' => Base64Url::encode($id)], self::apiKopfzeilen());
    }

    /**
     * POST /api/reveal
     *
     * Verbraucht das Geheimnis atomar. Alle drei Fehlerfälle enden in
     * derselben Antwort und haben denselben Weg dorthin.
     */
    private function apiAbrufen(Request $request): Response
    {
        $daten = $this->jsonRumpf($request);

        if ($daten === null) {
            return $this->fehler(400, 'ungueltige_anfrage');
        }

        $id = $daten['id'] ?? null;

        if (!is_string($id)) {
            return $this->fehler(400, 'ungueltige_anfrage');
        }

        // Auch eine formal unbrauchbare ID läuft hier durch: consume() führt
        // in jedem Fall dieselbe Abfrage aus.
        $payload = $this->store->consume($id);

        if ($payload === null) {
            return $this->nichtGefunden();
        }

        return Response::json(200, ['payload' => Base64Url::encode($payload)], self::apiKopfzeilen());
    }

    // ------------------------------------------------------------------
    // Antworten
    // ------------------------------------------------------------------

    private function nichtGefunden(): Response
    {
        return new Response(
            self::NICHT_GEFUNDEN_STATUS,
            self::NICHT_GEFUNDEN_BODY,
            ['Content-Type' => 'application/json; charset=utf-8'] + SecurityHeaders::forApi()
        );
    }

    private function fehler(int $status, string $schluessel): Response
    {
        return Response::json($status, ['fehler' => $schluessel], SecurityHeaders::forApi());
    }

    /**
     * @return array<string, string>
     */
    private static function apiKopfzeilen(): array
    {
        return ['X-Robots-Tag' => 'noindex, nofollow'] + SecurityHeaders::forApi();
    }

    private function methodeNichtErlaubt(): Response
    {
        return $this->fehler(405, 'methode_nicht_erlaubt');
    }

    /**
     * Wurde mehr geschickt, als angenommen wird - oder hat PHP den Rumpf
     * bereits verworfen?
     */
    private function istZuGross(Request $request): bool
    {
        if (strlen($request->body) > self::RUMPF_MAX_BYTES) {
            return true;
        }

        // PHP wirft den Rumpf weg, wenn er post_max_size überschreitet, und
        // hinterlässt eine leere Eingabe bei gesetzter Content-Length.
        return $request->body === '' && $request->angekuendigteGroesse > 0;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function jsonRumpf(Request $request): ?array
    {
        // base64url bläht um ein Drittel auf, dazu kommt der JSON-Rahmen.
        // Alles darüber wird gar nicht erst zerlegt: Ein Rumpf, der die
        // Grenze reißt, wird ohnehin abgelehnt, und das Zerlegen kostet
        // Speicher.
        if ($request->body === '' || strlen($request->body) > self::RUMPF_MAX_BYTES) {
            return null;
        }

        try {
            /** @var mixed $daten */
            $daten = json_decode($request->body, true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        if (!is_array($daten)) {
            return null;
        }

        /** @var array<string, mixed> $daten */
        return $daten;
    }
}
