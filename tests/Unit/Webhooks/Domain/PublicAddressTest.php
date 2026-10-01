<?php

declare(strict_types=1);

use Metered\Webhooks\Domain\Destination\PublicAddress;

it('allows addresses on the public internet', function (string $ip): void {
    expect(PublicAddress::allows($ip))->toBeTrue();
})->with([
    '93.184.216.34', '8.8.8.8', '1.1.1.1', '100.63.255.255', '100.128.0.0', '172.15.255.255', '172.32.0.0',
    '192.167.255.255', '169.253.255.255', '223.255.255.255',
    '2606:4700:4700::1111', '2001:4860:4860::8888', '::ffff:8.8.8.8', '64:ff9b::808:808',
]);

it('refuses every address a webhook must not reach', function (string $ip): void {
    expect(PublicAddress::allows($ip))->toBeFalse();
})->with([
    'this network' => '0.0.0.0',
    'private 10/8' => '10.1.2.3',
    'carrier-grade NAT' => '100.64.0.1',
    'loopback' => '127.0.0.1',
    'loopback, far end' => '127.255.255.254',
    'cloud metadata' => '169.254.169.254',
    'private 172.16/12' => '172.31.255.255',
    'IETF protocol assignments' => '192.0.0.8',
    'documentation 192.0.2/24' => '192.0.2.10',
    '6to4 relay' => '192.88.99.1',
    'private 192.168/16' => '192.168.1.1',
    'benchmarking' => '198.19.0.1',
    'documentation 198.51.100/24' => '198.51.100.7',
    'documentation 203.0.113/24' => '203.0.113.5',
    'multicast' => '239.255.255.250',
    'reserved' => '240.0.0.1',
    'broadcast' => '255.255.255.255',
    'v6 unspecified' => '::',
    'v6 loopback' => '::1',
    'v6 discard' => '100::1',
    'v6 documentation' => '2001:db8::1',
    'v6 unique local' => 'fd12:3456::1',
    'v6 link-local' => 'fe80::1',
    'v6 multicast' => 'ff02::1',
    'mapped loopback' => '::ffff:127.0.0.1',
    'mapped metadata' => '::ffff:169.254.169.254',
    'NAT64 private' => '64:ff9b::a00:1',
    'v6 IPv4-compatible loopback' => '::7f00:1',
    'v6 local-use NAT64' => '64:ff9b:1::a9fe:a9fe',
    'v6 6to4 to metadata' => '2002:a9fe:a9fe::1',
    'v6 Teredo' => '2001:0:4136:e378:8000:63bf:3fff:fdd2',
    'v6 IETF assignments' => '2001:2::1',
    'v6 documentation 3fff::/20' => '3fff::1',
    'v6 segment routing' => '5f00::1',
    'v6 outside global unicast' => '4000::1',
    'not an address' => 'hooks.example.com',
    'empty' => '',
]);

it('draws each range’s edge exactly', function (string $ip, bool $allowed): void {
    expect(PublicAddress::allows($ip))->toBe($allowed);
})->with([
    ['9.255.255.255', true], ['11.0.0.0', true],
    ['100.127.255.255', false], ['100.63.255.255', true],
    ['126.255.255.255', true], ['128.0.0.0', true],
    ['169.254.255.255', false], ['169.255.0.0', true],
    ['172.16.0.0', false],
    ['192.0.1.0', true], ['192.0.3.0', true], ['192.88.98.255', true], ['192.88.100.0', true],
    ['192.169.0.0', true], ['198.17.255.255', true], ['198.20.0.0', true],
    ['198.51.99.255', true], ['198.51.101.0', true], ['203.0.112.255', true], ['203.0.114.0', true],
    ['223.255.255.255', true], ['224.0.0.0', false],
    ['::2', false], ['1fff:ffff:ffff:ffff:ffff:ffff:ffff:ffff', false], ['2000::', true],
    ['3fff:ffff:ffff:ffff:ffff:ffff:ffff:ffff', true], ['4000::', false],
    ['2001:1ff:ffff:ffff::1', false], ['2001:200::', true],
    ['2001:db7:ffff::1', true], ['2001:db9::', true], ['2001:db8:ffff::1', false],
    ['2001:ffff:ffff::1', true], ['2002::', false], ['2002:ffff:ffff::1', false], ['2003::', true],
    ['3fff:fff:ffff::1', false], ['3fff:1000::', true],
    ['::fffe:a00:1', false], ['::ffff:a00:1', false], ['64:ff9b::1:a00:1', false], ['64:ff9b::808:808', true],
]);
