<?php

declare(strict_types=1);

namespace MaaleArna\MASES\Core\Localization;

use Illuminate\Support\Facades\Facade;

/**
 * @method static string getCurrentLocale()
 * @method static void setLocale(string $locale, \Illuminate\Session\Store|null $session = null)
 * @method static void applyLocale(\Illuminate\Http\Request $request, \Illuminate\Session\Store|null $session = null)
 * @method static \MaaleArna\MASES\Core\Localization\EthiopianDateTime now()
 * @method static \MaaleArna\MASES\Core\Localization\EthiopianDateTime fromGregorian(\DateTimeInterface $datetime)
 * @method static string formatDate(\MaaleArna\MASES\Core\Localization\EthiopianDateTime|null $datetime = null, string $locale = 'am')
 * @method static string formatDateLong(\MaaleArna\MASES\Core\Localization\EthiopianDateTime|null $datetime = null, string $locale = 'am')
 * @method static string formatTime(\MaaleArna\MASES\Core\Localization\EthiopianDateTime|null $datetime = null)
 * @method static string formatDateTime(\MaaleArna\MASES\Core\Localization\EthiopianDateTime|null $datetime = null, string $locale = 'am')
 * @method static string formatNumber(float|int $number, int $decimals = 2)
 * @method static string formatCurrency(float $amount, string $currency = 'ETB')
 * @method static string formatPercent(float $value, int $decimals = 1)
 * @method static \MaaleArna\MASES\Core\Localization\EthiopianDateTime parseDate(string $dateString)
 * @method static \MaaleArna\MASES\Core\Localization\EthiopianDateTime parseDateTime(string $dateTimeString)
 * @method static \MaaleArna\MASES\Core\Localization\EthiopianFormatter getFormatter()
 * @method static \MaaleArna\MASES\Core\Localization\EthiopianCalendar getCalendar()
 * @method static \MaaleArna\MASES\Core\Localization\LocaleManager getLocaleManager()
 *
 * @see \MaaleArna\MASES\Core\Localization\LocalizationManager
 */
final class LocalizationFacade extends Facade
{
    /**
     * Get the registered name of the component.
     */
    protected static function getFacadeAccessor(): string
    {
        return 'mases.localization';
    }
}