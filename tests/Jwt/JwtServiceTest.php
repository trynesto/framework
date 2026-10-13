<?php

declare(strict_types=1);

namespace Nesto\Tests\Jwt;

use Nesto\Common\Exceptions\UnauthorizedException;
use Nesto\Jwt\JwtException;
use Nesto\Jwt\JwtService;
use PHPUnit\Framework\TestCase;

final class JwtServiceTest extends TestCase
{
    private function hs(array $extra = []): JwtService
    {
        return new JwtService(['secret' => 'top-secret', 'signOptions' => ['expiresIn' => '1h']] + $extra);
    }

    public function testSignAndVerifyHs256(): void
    {
        $jwt = $this->hs();
        $token = $jwt->sign(['sub' => 42, 'name' => 'Ünïcode/slash']);
        self::assertCount(3, explode('.', $token));

        $payload = $jwt->verify($token);
        self::assertSame(42, $payload['sub']);
        self::assertSame('Ünïcode/slash', $payload['name']);
        self::assertSame($payload['iat'] + 3600, $payload['exp']);
        self::assertSame(['alg' => 'HS256', 'typ' => 'JWT'], $jwt->decode($token, ['complete' => true])['header']);
        self::assertSame($payload, $jwt->decode($token));
    }

    public function testHs384AndHs512(): void
    {
        foreach (['HS384', 'HS512'] as $alg) {
            $jwt = new JwtService(['secret' => 's', 'algorithm' => $alg]);
            $token = $jwt->sign(['a' => 1]);
            self::assertSame($alg, $jwt->decode($token, ['complete' => true])['header']['alg']);
            self::assertSame(1, $jwt->verify($token)['a']);
        }
    }

    public function testTamperingAndWrongSecretAreRejected(): void
    {
        $jwt = $this->hs();
        $token = $jwt->sign(['sub' => 1, 'role' => 'user']);
        [$h, $p, $s] = explode('.', $token);

        $forgedPayload = JwtService::base64UrlEncode(json_encode(['sub' => 1, 'role' => 'admin']));
        try {
            $jwt->verify("$h.$forgedPayload.$s");
            self::fail('tampered payload accepted');
        } catch (JwtException $e) {
            self::assertSame(JwtException::INVALID_SIGNATURE, $e->reason());
            self::assertInstanceOf(UnauthorizedException::class, $e);
            self::assertSame(401, $e->getStatus());
        }

        try {
            (new JwtService(['secret' => 'other']))->verify($token);
            self::fail('wrong secret accepted');
        } catch (JwtException $e) {
            self::assertSame(JwtException::INVALID_SIGNATURE, $e->reason());
        }

        try {
            $jwt->verify('not.a.jwt.at.all');
        } catch (JwtException $e) {
            self::assertSame(JwtException::MALFORMED, $e->reason());
        }

        // alg=none and algorithm confusion are refused
        $none = JwtService::base64UrlEncode('{"alg":"none","typ":"JWT"}') . ".$p.";
        try {
            $jwt->verify($none);
            self::fail('alg none accepted');
        } catch (JwtException $e) {
            self::assertSame(JwtException::ALGORITHM, $e->reason());
        }
    }

    public function testExpiryNotBeforeAndClockTolerance(): void
    {
        $jwt = $this->hs();
        $expired = $jwt->sign(['sub' => 1, 'exp' => time() - 10]);
        try {
            $jwt->verify($expired);
            self::fail('expired accepted');
        } catch (JwtException $e) {
            self::assertSame(JwtException::EXPIRED, $e->reason());
            self::assertSame('jwt expired', $e->getMessage());
        }
        self::assertSame(1, $jwt->verify($expired, ['clockTolerance' => 60])['sub']);
        self::assertSame(1, $jwt->verify($expired, ['ignoreExpiration' => true])['sub']);

        $future = $jwt->sign(['sub' => 2], ['notBefore' => '10m']);
        try {
            $jwt->verify($future);
            self::fail('nbf accepted');
        } catch (JwtException $e) {
            self::assertSame(JwtException::NOT_ACTIVE, $e->reason());
        }

        $short = $jwt->sign(['sub' => 3], ['expiresIn' => 15]);
        self::assertSame(time() + 15, $jwt->decode($short)['exp']);
    }

    public function testIssuerAudienceSubjectClaims(): void
    {
        $jwt = $this->hs();
        $token = $jwt->sign(['x' => 1], ['issuer' => 'nesto', 'audience' => ['web', 'mobile'], 'subject' => 'u1', 'jwtid' => 'j1']);
        $payload = $jwt->verify($token, ['issuer' => 'nesto', 'audience' => 'mobile', 'subject' => 'u1', 'jwtid' => 'j1']);
        self::assertSame('nesto', $payload['iss']);
        self::assertSame(['web', 'mobile'], $payload['aud']);

        $cases = [[['issuer' => 'other'], JwtException::ISSUER], [['audience' => 'tv'], JwtException::AUDIENCE], [['subject' => 'u2'], JwtException::SUBJECT]];
        foreach ($cases as [$options, $expected]) {
            try {
                $jwt->verify($token, $options);
                self::fail("expected $expected");
            } catch (JwtException $e) {
                self::assertSame($expected, $e->reason());
            }
        }
    }

    public function testRs256WithGeneratedKeyPair(): void
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        self::assertNotFalse($key);
        openssl_pkey_export($key, $privatePem);
        $publicPem = openssl_pkey_get_details($key)['key'];

        $signer = new JwtService(['algorithm' => 'RS256', 'privateKey' => $privatePem, 'signOptions' => ['expiresIn' => '1h']]);
        $verifier = new JwtService(['algorithm' => 'RS256', 'publicKey' => $publicPem]);

        $token = $signer->sign(['sub' => 'rsa']);
        self::assertSame('RS256', $signer->decode($token, ['complete' => true])['header']['alg']);
        self::assertSame('rsa', $verifier->verify($token)['sub']);
        self::assertSame('rsa', $signer->verify($token)['sub'], 'private key PEM also verifies');

        foreach (['RS384', 'RS512'] as $alg) {
            $t = $signer->sign(['a' => $alg], ['algorithm' => $alg]);
            self::assertSame($alg, $verifier->verify($t, ['algorithms' => [$alg]])['a']);
        }

        // a token signed with another key pair is rejected
        $other = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($other, $otherPem);
        $forged = (new JwtService(['algorithm' => 'RS256', 'privateKey' => $otherPem]))->sign(['sub' => 'evil']);
        try {
            $verifier->verify($forged);
            self::fail('foreign key accepted');
        } catch (JwtException $e) {
            self::assertSame(JwtException::INVALID_SIGNATURE, $e->reason());
        }

        // HS256 token presented to an RS256 verifier (key confusion) is rejected by algorithm
        $hs = (new JwtService(['secret' => $publicPem]))->sign(['sub' => 'confused']);
        try {
            $verifier->verify($hs);
            self::fail('algorithm confusion accepted');
        } catch (JwtException $e) {
            self::assertSame(JwtException::ALGORITHM, $e->reason());
        }
    }

    public function testParseDuration(): void
    {
        self::assertSame(15, JwtService::parseDuration('15s'));
        self::assertSame(1800, JwtService::parseDuration('30m'));
        self::assertSame(3600, JwtService::parseDuration('1h'));
        self::assertSame(604800, JwtService::parseDuration('7d'));
        self::assertSame(5400, JwtService::parseDuration('1h 30m'));
        self::assertSame(120, JwtService::parseDuration('2 minutes'));
        self::assertSame(90, JwtService::parseDuration(90));
        self::assertSame(90, JwtService::parseDuration('90'));
        $this->expectException(\InvalidArgumentException::class);
        JwtService::parseDuration('1 fortnight');
    }
}
