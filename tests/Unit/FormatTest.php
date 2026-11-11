<?php

namespace Tests\Unit;

use App\Support\Format;
use PHPUnit\Framework\TestCase;

class FormatTest extends TestCase
{
    public function test_money_uses_comma_thousands_and_dot_decimals(): void
    {
        $this->assertSame('$13,095.50', Format::money(13095.5));
        $this->assertSame('$0.00', Format::money(null));
        $this->assertSame('-$3.25', Format::money(-3.25));
        $this->assertSame('$1,234.00', Format::money('1234'));
    }

    public function test_number_hides_decimals_for_whole_values(): void
    {
        $this->assertSame('10', Format::number(10));
        $this->assertSame('1,000', Format::number(1000));
        $this->assertSame('1.5', Format::number(1.5));
        $this->assertSame('1,234.25', Format::number(1234.25));
        $this->assertSame('0', Format::number(0));
    }

    public function test_percent(): void
    {
        $this->assertSame('39.8%', Format::percent(39.84));
        $this->assertSame('12%', Format::percent(12, 0));
    }
}
