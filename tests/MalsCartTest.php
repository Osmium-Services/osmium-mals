<?php

declare(strict_types=1);

namespace Tests\Services\Mals;

use Osmium\Services\Mals\Models\MalsCart;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../models/MalsCart.php';

/**
 * The Mals cart summary and settings: what a theme reads to show the cart and post add-to-cart forms.
 */
final class MalsCartTest extends TestCase
{
    private function config(array $overrides = []): object
    {
        return (object) ($overrides + [
            'enabled' => true,
            'cartUserID' => '12345',
            'addToCartURL' => 'https://ww9.aitsafe.com/cf/add.cfm',
            'checkoutURL' => 'https://ww9.aitsafe.com/cf/review.cfm?userid={CARTUSERID}',
            'hideZeroItems' => false,
        ]);
    }

    public function test_hosted_payment_names_mals_and_its_settings_page(): void
    {
        $this->assertSame(['label' => 'Mals', 'settingsRoute' => 'settings/mals/'], MalsCart::hostedPayment([]));
    }

    public function test_no_file_means_off_with_empty_settings(): void
    {
        $config = MalsCart::fromContent(null);

        $this->assertFalse($config->enabled);
        $this->assertSame('', $config->cartUserID);
        $this->assertFalse(MalsCart::isReady($config));
    }

    public function test_settings_are_read_after_the_php_guard_line(): void
    {
        $content = "<?php exit(); ?>\n" . '{"mals": {"enabled": true, "cartUserID": "99", "addToCartURL": "https://a/", "checkoutURL": "https://b/{CARTUSERID}", "hideZeroItems": true}}';

        $config = MalsCart::fromContent($content);

        $this->assertSame('99', $config->cartUserID);
        $this->assertTrue($config->hideZeroItems);
        $this->assertTrue(MalsCart::isReady($config));
    }

    public function test_unreadable_or_incomplete_files_fall_back_to_defaults(): void
    {
        $this->assertFalse(MalsCart::fromContent('not json at all')->enabled);
        $this->assertFalse(MalsCart::fromContent("<?php exit(); ?>\n{broken")->enabled);
        $this->assertSame('', MalsCart::fromContent("<?php exit(); ?>\n" . '{"mals": {"enabled": true}}')->cartUserID);
    }

    public function test_it_is_ready_only_when_enabled_with_an_id_and_both_urls(): void
    {
        $this->assertTrue(MalsCart::isReady($this->config()));
        $this->assertFalse(MalsCart::isReady($this->config(['enabled' => false])));
        $this->assertFalse(MalsCart::isReady($this->config(['cartUserID' => ''])));
        $this->assertFalse(MalsCart::isReady($this->config(['addToCartURL' => ''])));
        $this->assertFalse(MalsCart::isReady($this->config(['checkoutURL' => ''])));
    }

    public function test_a_theme_gets_nothing_when_the_service_is_not_ready(): void
    {
        $session = [];

        $this->assertNull(MalsCart::build($this->config(['enabled' => false]), $session, []));
        $this->assertSame([], $session);
    }

    public function test_the_checkout_link_has_the_cart_id_filled_in_and_encoded(): void
    {
        $session = [];

        $data = MalsCart::build($this->config(['cartUserID' => 'a b']), $session, []);

        $this->assertSame('https://ww9.aitsafe.com/cf/review.cfm?userid=a%20b', $data['checkoutLink']);
        $this->assertSame('a b', $data['cartID']);
        $this->assertSame('https://ww9.aitsafe.com/cf/add.cfm', $data['addItemLink']);
    }

    public function test_an_empty_cart_has_no_items_and_the_hide_setting_is_passed_on(): void
    {
        $session = [];

        $data = MalsCart::build($this->config(['hideZeroItems' => true]), $session, []);

        $this->assertSame(0, $data['cartTotalItems']);
        $this->assertSame(0.0, $data['cartTotalExcTax']);
        $this->assertTrue($data['hideZeroItems']);
    }

    public function test_mals_return_sets_the_count_from_the_stashed_query_string(): void
    {
        $session = ['qs' => 'qty=3.00&sub=141.67&shp=0.00&tot=424.01&unt=1.25&sd='];

        $data = MalsCart::build($this->config(), $session, []);

        $this->assertSame(3, $data['cartTotalItems']);
        $this->assertSame(424.01, $data['cartTotalExcTax']);
    }

    public function test_the_stash_is_used_once_then_the_count_is_kept_in_the_session(): void
    {
        $session = ['qs' => 'qty=2&tot=10'];

        MalsCart::build($this->config(), $session, []);
        $next = MalsCart::build($this->config(), $session, ['liveqty' => '9']);

        $this->assertArrayNotHasKey('qs', $session);
        $this->assertSame(2, $next['cartTotalItems']);
    }

    public function test_a_stashed_query_string_without_cart_values_is_left_for_others(): void
    {
        $session = ['qs' => 'utm_source=news'];

        $data = MalsCart::build($this->config(), $session, []);

        $this->assertSame('utm_source=news', $session['qs']);
        $this->assertSame(0, $data['cartTotalItems']);
    }

    public function test_the_return_that_empties_the_cart_clears_a_kept_count(): void
    {
        $session = ['mals_cart' => ['items' => 4, 'total' => 50.0], 'qs' => 'r=1'];

        $data = MalsCart::build($this->config(), $session, ['liveqty' => '4']);

        $this->assertSame(0, $data['cartTotalItems']);
        $this->assertSame(0.0, $data['cartTotalExcTax']);
        $this->assertSame(0, MalsCart::summary([], $session['mals_cart'], [])['items']);
    }

    public function test_cookies_are_the_fallback_when_nothing_else_is_known(): void
    {
        $session = [];

        $data = MalsCart::build($this->config(), $session, ['liveqty' => '5', 'livetot' => '99.5']);

        $this->assertSame(5, $data['cartTotalItems']);
        $this->assertSame(99.5, $data['cartTotalExcTax']);
    }

    public function test_negative_or_junk_values_never_show_a_negative_count(): void
    {
        $this->assertSame(['items' => 0, 'total' => 0.0], MalsCart::summary(['qty' => '-4', 'tot' => '-1'], null, []));
        $this->assertSame(['items' => 0, 'total' => 0.0], MalsCart::summary(['qty' => 'abc', 'tot' => 'x'], null, []));
    }
}
