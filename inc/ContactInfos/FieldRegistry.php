<?php

declare(strict_types=1);

namespace SFX\ContactInfos;

final class FieldRegistry
{
    /**
     * @return array<string, string>
     */
    public static function get_fields(): array
    {
        return [
            'company'  => __('Company Name', 'sfxtheme'),
            'director' => __('Director', 'sfxtheme'),
            'street'   => __('Street', 'sfxtheme'),
            'zip'      => __('ZIP Code', 'sfxtheme'),
            'city'     => __('City', 'sfxtheme'),
            'country'  => __('Country', 'sfxtheme'),
            'address'  => __('Full Address', 'sfxtheme'),
            'phone'    => __('Phone', 'sfxtheme'),
            'mobile'   => __('Mobile', 'sfxtheme'),
            'fax'      => __('Fax', 'sfxtheme'),
            'email'    => __('Email', 'sfxtheme'),
            'tax_id'   => __('Tax ID', 'sfxtheme'),
            'vat'      => __('VAT Number', 'sfxtheme'),
            'hrb'      => __('HRB Number', 'sfxtheme'),
            'court'    => __('Court', 'sfxtheme'),
            'dsb'      => __('DSB', 'sfxtheme'),
            'opening'  => __('Opening Hours', 'sfxtheme'),
            'maplink'  => __('Map Link', 'sfxtheme'),
        ];
    }
}
