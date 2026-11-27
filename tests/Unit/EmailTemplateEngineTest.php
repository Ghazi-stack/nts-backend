<?php

namespace Tests\Unit;

use App\Services\EmailTemplateEngine;
use PHPUnit\Framework\TestCase;

class EmailTemplateEngineTest extends TestCase
{
    private function render(string $tpl, array $data = [], array $phrases = []): string
    {
        return (new EmailTemplateEngine())->render($tpl, $data, $phrases);
    }

    public function test_variables_and_nested_paths(): void
    {
        $this->assertSame(
            'Order #1001 for Ali',
            $this->render('Order #{{order.id}} for {{customer.first_name}}', [
                'order' => ['id' => 1001],
                'customer' => ['first_name' => 'Ali'],
            ])
        );
    }

    public function test_values_are_escaped_unless_triple_braces(): void
    {
        $data = ['name' => '<b>x</b>'];

        $this->assertSame('&lt;b&gt;x&lt;/b&gt;', $this->render('{{name}}', $data));
        $this->assertSame('<b>x</b>', $this->render('{{{name}}}', $data));
    }

    public function test_unknown_values_render_empty(): void
    {
        $this->assertSame('[]', $this->render('[{{missing.path}}]'));
    }

    public function test_each_loop_with_meta_and_outer_scope(): void
    {
        $tpl = '{{#each items}}{{@number}}.{{name}} x{{qty}} ({{../store.name}}{{store.name}}){{#unless @last}}, {{/unless}}{{/each}}';

        $this->assertSame(
            '1.Pen x2 (ShopShop), 2.Cup x1 (ShopShop)',
            $this->render($tpl, [
                'store' => ['name' => 'Shop'],
                'items' => [['name' => 'Pen', 'qty' => 2], ['name' => 'Cup', 'qty' => 1]],
            ])
        );
    }

    public function test_each_else_when_empty(): void
    {
        $this->assertSame('none', $this->render('{{#each items}}x{{else}}none{{/each}}', ['items' => []]));
    }

    public function test_if_else_and_unless(): void
    {
        $tpl = '{{#if discount}}-{{discount}}{{else}}no discount{{/if}}';

        $this->assertSame('-5', $this->render($tpl, ['discount' => '5']));
        $this->assertSame('no discount', $this->render($tpl, ['discount' => '']));
        $this->assertSame('no discount', $this->render($tpl, ['discount' => '0']));
        $this->assertSame('ok', $this->render('{{#unless flag}}ok{{/unless}}', ['flag' => false]));
    }

    public function test_lang_phrases_render_their_own_placeholders(): void
    {
        $this->assertSame(
            'Thanks for shopping at <b>Shop</b>',
            $this->render(
                "{{lang 'thanks'}}",
                ['store' => ['name' => 'Shop']],
                ['thanks' => 'Thanks for shopping at <b>{{store.name}}</b>']
            )
        );
    }

    public function test_legacy_top_level_phrase_variables_still_work(): void
    {
        // Old seeded templates used {{title}} / {{store}} directly.
        $this->assertSame(
            '<h1>Thanks</h1> Shop',
            $this->render('<h1>{{{title}}}</h1> {{store}}', ['title' => 'Thanks', 'store' => 'Shop'])
        );
    }

    public function test_comments_are_removed(): void
    {
        $this->assertSame('ab', $this->render('a{{!-- hidden {{x}} --}}b{{! also hidden }}'));
    }

    public function test_unbalanced_or_unknown_blocks_do_not_throw(): void
    {
        $this->assertSame('{{#foo bar}}x', $this->render('{{#foo bar}}x'));
        $this->assertSame('x{{/if}}', $this->render('x{{/if}}'));
        $this->assertSame('open', $this->render('{{#if a}}open', ['a' => true]));
    }

    public function test_no_php_or_blade_is_executed(): void
    {
        $tpl = "<?php echo 'pwned'; ?>{{ system('id') }}@php echo 1; @endphp";

        $out = $this->render($tpl, []);

        $this->assertStringContainsString("<?php echo 'pwned'; ?>", $out);
        $this->assertStringContainsString('@php echo 1; @endphp', $out);
    }
}
