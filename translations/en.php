<?php

/**
 * The base catalogue. Every other locale is measured against this file, and
 * `php bin/console i18n:check` fails if one drifts from it.
 *
 * Keys are flat and dotted, grouped by where the string appears. Messages are
 * ICU MessageFormat: anything in braces is an argument, and a count that reads
 * differently in the singular takes a `plural` block so that a language with
 * more than two forms can say all of them. Adding a language means copying
 * this file to `translations/<locale>.php` and translating the right-hand
 * side — see docs/development.md#adding-a-language.
 *
 * Keys beginning `js.` are handed to the browser by `js_translations()` in the
 * layout. They live here rather than in a JavaScript file so that one check
 * covers every string the application can show.
 */

declare(strict_types=1);

return [
    // -----------------------------------------------------------------------
    // Shared vocabulary
    // -----------------------------------------------------------------------
    'account.choose_picture' => 'Choose a picture',
    'account.confirm_password' => 'Confirm new password',
    'account.current_password' => 'Current password',
    'account.email_cancel' => 'cancel',
    'account.email_hint' => 'Changing it sends a confirmation link to the new address.',
    'account.email_none' =>
        'This account has no mailbox of its own. An Owner of your household can give it an address.',
    'account.email_pending' => 'Waiting for confirmation of {email} —',
    'account.email_resend' => 'resend',
    'account.temporary_password' => 'Temporary password',
    'account.must_change_heading' => 'Choose a password of your own',
    'account.must_change_note' =>
        'You are signed in with a password somebody else chose for you. Set one of your own below '
        . 'and the rest of Renovo opens up.',
    'account.new_password' => 'New password',
    'account.picture_hint' =>
        'PNG, JPEG, WebP or GIF, up to {kilobytes} KB. It is cropped square and resized, and the '
        . 'original is not kept.',
    'account.remove_picture' => 'Remove',
    'account.upload_picture' => 'Upload picture',
    'account.save_details' => 'Save changes',
    'account.save_password' => 'Update password',
    'account.sign_out_others' => 'Sign out my other sessions',
    'account.sign_out_others_hint' =>
        'Leave this on unless you meant somebody else to stay signed in. This device stays signed '
        . 'in either way.',
    'account.who_you_are' => 'Who you are',
    'account.your_password' => 'Your password',
    'audit_action.account.avatar_changed' => 'Picture changed',
    'audit_action.account.avatar_removed' => 'Picture removed',
    'audit_action.account.email_change_requested' => 'Email change requested',
    'audit_action.account.email_change_resent' => 'Email change link sent again',
    'audit_action.account.email_changed' => 'Email address changed',
    'audit_action.account.name_changed' => 'Name changed',
    'audit_action.member.added' => 'Member added',
    'audit_action.member.invite_resent' => 'Invitation resent',
    'audit_action.member.login_restored' => 'Login restored',
    'audit_action.member.login_revoked' => 'Login revoked',
    'audit_action.member.password_reset_sent' => 'Password reset sent',
    'audit_action.member.removed' => 'Member removed from household',
    'audit_action.member.temporary_password_issued' => 'Temporary password issued',
    'auth.back_to_sign_in' => 'Back to sign in',
    'auth.demo_banner' => 'This instance is a read-only demonstration. You can look around; nothing you change is saved.',
    'auth.email_placeholder' => 'you@example.com',
    'auth.hide_password' => 'Hide password',
    'auth.meter.fair' => 'Fair',
    'auth.meter.good' => 'Good',
    'auth.meter.minimum' => 'At least {count, plural, one {# character} other {# characters}}.',
    'auth.meter.strong' => 'Strong',
    'auth.meter.too_short' => 'Too short',
    'auth.meter.weak' => 'Weak',
    'auth.or' => 'or',
    'auth.show_password' => 'Show password',
    'auth_brand.currency_body' => 'Pay in euros or dollars; totals and budgets convert to your base currency.',
    'auth_brand.currency_title' => 'Any currency, one total',
    'auth_brand.footer' => 'Self-hosted. Your data stays on your own server.',
    'auth_brand.household_body' =>
        'Owners, Editors, Contributors and Viewers each see and change what their role allows.',
    'auth_brand.household_title' => 'Built for the whole household',
    'auth_brand.pitch' => 'Every subscription and recurring bill in the household, in one place.',
    'auth_brand.reminders_body' =>
        'Renewals, trial conversions, price rises and budgets, by email, chat apps or push notifications.',
    'auth_brand.reminders_title' => 'Reminders before money moves',
    'auth_email_change_done.intro' => 'Your account now signs in and receives mail at {email}.',
    'auth_email_change_done.title' => 'Email address changed',
    'auth_email_change_failed.back' => 'Back to your account',
    'auth_email_change_failed.intro' =>
        'Confirmation links are valid for one hour and can be used once. Your address has not been '
        . 'changed.',
    'auth_email_change_failed.title' => 'That link did not work',
    'auth_invite.choose_password' => 'Choose a password',
    'auth_invite.confirm_password' => 'Confirm password',
    'auth_invite.intro' =>
        'Your account is nearly ready. Choose a password and it is yours — nobody else, including '
        . 'whoever invited you, ever sees it.',
    'auth_invite.intro_named' =>
        'You have been invited to join {household}. Choose a password and it is yours — nobody else, '
        . 'including whoever invited you, ever sees it.',
    'auth_invite.join' => 'Set password and join',
    'auth_invite.title' => 'Join the household',
    'auth_invite_expired.intro' =>
        'Invitations are valid for seven days, and each one can be used once. Ask whoever invited '
        . 'you to send another.',
    'auth_invite_expired.title' => 'This invitation has expired',
    'common.none_symbol' => '—',

    // -----------------------------------------------------------------------
    // Domain vocabulary — the enum labels. The keys are the ones the enums
    // themselves return from labelKey().
    // -----------------------------------------------------------------------
    'cycle.weekly' => 'Weekly',
    'cycle.monthly' => 'Monthly',
    'cycle.quarterly' => 'Quarterly',
    'cycle.yearly' => 'Yearly',
    'cycle.weekly_every' => '{n, plural, =2 {Fortnightly} other {Every # weeks}}',
    'cycle.monthly_every' => '{n, plural, one {Monthly} other {Every # months}}',
    'cycle.yearly_every' => '{n, plural, one {Yearly} other {Every # years}}',
    'cycle.custom' => 'Custom',
    'cycle.custom_days' => 'Every {days, plural, one {# day} other {# days}}',

    // currency — the name beside each code in a currency select
    'currency.option' => '{code} - {name}',
    'currency.option_symbol' => '{code} · {symbol}',
    'currency.name.AED' => 'United Arab Emirates Dirham',
    'currency.name.AFN' => 'Afghan Afghani',
    'currency.name.ALL' => 'Albanian Lek',
    'currency.name.AMD' => 'Armenian Dram',
    'currency.name.ANG' => 'Netherlands Antillean Guilder',
    'currency.name.AOA' => 'Angolan Kwanza',
    'currency.name.ARS' => 'Argentine Peso',
    'currency.name.AUD' => 'Australian Dollar',
    'currency.name.AWG' => 'Aruban Florin',
    'currency.name.AZN' => 'Azerbaijani Manat',
    'currency.name.BAM' => 'Bosnia-Herzegovina Convertible Mark',
    'currency.name.BBD' => 'Barbadian Dollar',
    'currency.name.BDT' => 'Bangladeshi Taka',
    'currency.name.BGN' => 'Bulgarian Lev',
    'currency.name.BHD' => 'Bahraini Dinar',
    'currency.name.BIF' => 'Burundian Franc',
    'currency.name.BMD' => 'Bermudan Dollar',
    'currency.name.BND' => 'Brunei Dollar',
    'currency.name.BOB' => 'Bolivian Boliviano',
    'currency.name.BRL' => 'Brazilian Real',
    'currency.name.BSD' => 'Bahamian Dollar',
    'currency.name.BTN' => 'Bhutanese Ngultrum',
    'currency.name.BWP' => 'Botswanan Pula',
    'currency.name.BYN' => 'Belarusian Ruble',
    'currency.name.BZD' => 'Belize Dollar',
    'currency.name.CAD' => 'Canadian Dollar',
    'currency.name.CDF' => 'Congolese Franc',
    'currency.name.CHF' => 'Swiss Franc',
    'currency.name.CLP' => 'Chilean Peso',
    'currency.name.CNY' => 'Chinese Yuan',
    'currency.name.COP' => 'Colombian Peso',
    'currency.name.CRC' => 'Costa Rican Colón',
    'currency.name.CUP' => 'Cuban Peso',
    'currency.name.CVE' => 'Cape Verdean Escudo',
    'currency.name.CZK' => 'Czech Koruna',
    'currency.name.DJF' => 'Djiboutian Franc',
    'currency.name.DKK' => 'Danish Krone',
    'currency.name.DOP' => 'Dominican Peso',
    'currency.name.DZD' => 'Algerian Dinar',
    'currency.name.EGP' => 'Egyptian Pound',
    'currency.name.ERN' => 'Eritrean Nakfa',
    'currency.name.ETB' => 'Ethiopian Birr',
    'currency.name.EUR' => 'Euro',
    'currency.name.FJD' => 'Fijian Dollar',
    'currency.name.FKP' => 'Falkland Islands Pound',
    'currency.name.GBP' => 'Pounds sterling',
    'currency.name.GEL' => 'Georgian Lari',
    'currency.name.GHS' => 'Ghanaian Cedi',
    'currency.name.GIP' => 'Gibraltar Pound',
    'currency.name.GMD' => 'Gambian Dalasi',
    'currency.name.GNF' => 'Guinean Franc',
    'currency.name.GTQ' => 'Guatemalan Quetzal',
    'currency.name.GYD' => 'Guyanaese Dollar',
    'currency.name.HKD' => 'Hong Kong Dollar',
    'currency.name.HNL' => 'Honduran Lempira',
    'currency.name.HTG' => 'Haitian Gourde',
    'currency.name.HUF' => 'Hungarian Forint',
    'currency.name.IDR' => 'Indonesian Rupiah',
    'currency.name.ILS' => 'Israeli New Shekel',
    'currency.name.INR' => 'Indian Rupee',
    'currency.name.IQD' => 'Iraqi Dinar',
    'currency.name.IRR' => 'Iranian Rial',
    'currency.name.ISK' => 'Icelandic Króna',
    'currency.name.JMD' => 'Jamaican Dollar',
    'currency.name.JOD' => 'Jordanian Dinar',
    'currency.name.JPY' => 'Japanese Yen',
    'currency.name.KES' => 'Kenyan Shilling',
    'currency.name.KGS' => 'Kyrgyz Som',
    'currency.name.KHR' => 'Cambodian Riel',
    'currency.name.KMF' => 'Comorian Franc',
    'currency.name.KPW' => 'North Korean Won',
    'currency.name.KRW' => 'South Korean Won',
    'currency.name.KWD' => 'Kuwaiti Dinar',
    'currency.name.KYD' => 'Cayman Islands Dollar',
    'currency.name.KZT' => 'Kazakhstani Tenge',
    'currency.name.LAK' => 'Laotian Kip',
    'currency.name.LBP' => 'Lebanese Pound',
    'currency.name.LKR' => 'Sri Lankan Rupee',
    'currency.name.LRD' => 'Liberian Dollar',
    'currency.name.LSL' => 'Lesotho Loti',
    'currency.name.LYD' => 'Libyan Dinar',
    'currency.name.MAD' => 'Moroccan Dirham',
    'currency.name.MDL' => 'Moldovan Leu',
    'currency.name.MGA' => 'Malagasy Ariary',
    'currency.name.MKD' => 'Macedonian Denar',
    'currency.name.MMK' => 'Myanmar Kyat',
    'currency.name.MNT' => 'Mongolian Tugrik',
    'currency.name.MOP' => 'Macanese Pataca',
    'currency.name.MRU' => 'Mauritanian Ouguiya',
    'currency.name.MUR' => 'Mauritian Rupee',
    'currency.name.MVR' => 'Maldivian Rufiyaa',
    'currency.name.MWK' => 'Malawian Kwacha',
    'currency.name.MXN' => 'Mexican Peso',
    'currency.name.MYR' => 'Malaysian Ringgit',
    'currency.name.MZN' => 'Mozambican Metical',
    'currency.name.NAD' => 'Namibian Dollar',
    'currency.name.NGN' => 'Nigerian Naira',
    'currency.name.NIO' => 'Nicaraguan Córdoba',
    'currency.name.NOK' => 'Norwegian Krone',
    'currency.name.NPR' => 'Nepalese Rupee',
    'currency.name.NZD' => 'New Zealand Dollar',
    'currency.name.OMR' => 'Omani Rial',
    'currency.name.PAB' => 'Panamanian Balboa',
    'currency.name.PEN' => 'Peruvian Sol',
    'currency.name.PGK' => 'Papua New Guinean Kina',
    'currency.name.PHP' => 'Philippine Peso',
    'currency.name.PKR' => 'Pakistani Rupee',
    'currency.name.PLN' => 'Polish Zloty',
    'currency.name.PYG' => 'Paraguayan Guarani',
    'currency.name.QAR' => 'Qatari Riyal',
    'currency.name.RON' => 'Romanian Leu',
    'currency.name.RSD' => 'Serbian Dinar',
    'currency.name.RUB' => 'Russian Ruble',
    'currency.name.RWF' => 'Rwandan Franc',
    'currency.name.SAR' => 'Saudi Riyal',
    'currency.name.SBD' => 'Solomon Islands Dollar',
    'currency.name.SCR' => 'Seychellois Rupee',
    'currency.name.SDG' => 'Sudanese Pound',
    'currency.name.SEK' => 'Swedish Krona',
    'currency.name.SGD' => 'Singapore Dollar',
    'currency.name.SHP' => 'St. Helena Pound',
    'currency.name.SLE' => 'Sierra Leonean Leone',
    'currency.name.SOS' => 'Somali Shilling',
    'currency.name.SRD' => 'Surinamese Dollar',
    'currency.name.SSP' => 'South Sudanese Pound',
    'currency.name.STN' => 'São Tomé & Príncipe Dobra',
    'currency.name.SVC' => 'Salvadoran Colón',
    'currency.name.SYP' => 'Syrian Pound',
    'currency.name.SZL' => 'Swazi Lilangeni',
    'currency.name.THB' => 'Thai Baht',
    'currency.name.TJS' => 'Tajikistani Somoni',
    'currency.name.TMT' => 'Turkmenistani Manat',
    'currency.name.TND' => 'Tunisian Dinar',
    'currency.name.TOP' => 'Tongan Paʻanga',
    'currency.name.TRY' => 'Turkish Lira',
    'currency.name.TTD' => 'Trinidad & Tobago Dollar',
    'currency.name.TWD' => 'New Taiwan Dollar',
    'currency.name.TZS' => 'Tanzanian Shilling',
    'currency.name.UAH' => 'Ukrainian Hryvnia',
    'currency.name.UGX' => 'Ugandan Shilling',
    'currency.name.USD' => 'US Dollar',
    'currency.name.UYU' => 'Uruguayan Peso',
    'currency.name.UZS' => 'Uzbekistani Som',
    'currency.name.VED' => 'Bolívar Soberano',
    'currency.name.VES' => 'Venezuelan Bolívar',
    'currency.name.VND' => 'Vietnamese Dong',
    'currency.name.VUV' => 'Vanuatu Vatu',
    'currency.name.WST' => 'Samoan Tala',
    'currency.name.XAF' => 'Central African CFA Franc',
    'currency.name.XCD' => 'East Caribbean Dollar',
    'currency.name.XCG' => 'Caribbean Guilder',
    'currency.name.XOF' => 'West African CFA Franc',
    'currency.name.XPF' => 'CFP Franc',
    'currency.name.YER' => 'Yemeni Rial',
    'currency.name.ZAR' => 'South African Rand',
    'currency.name.ZMW' => 'Zambian Kwacha',
    'currency.name.ZWG' => 'Zimbabwean Gold',

    'error.auth.disabled' =>
        'This account has been closed by an administrator of your household. Ask them to restore it.',
    'error.avatar.dimensions' => 'That image is too large to work with. Try a smaller one.',
    'error.avatar.too_large' => 'A picture must be {kilobytes} KB or smaller.',
    'error.avatar.type' => 'That file is not a PNG, JPEG, WebP or GIF image.',
    'error.budget.household_isolated' =>
        'A household budget is not available while members\' subscriptions are kept separate.',
    'error.budget.subject_self_only' => 'You can set a budget for your own spending only.',
    'error.email.no_mailbox' =>
        'This account has no mailbox of its own, so it cannot confirm a new address. An Owner of your '
        . 'household can change it for you.',
    'error.email.nothing_pending' => 'There is no change of address waiting to be confirmed.',
    'error.email.unchanged' => 'That is already your email address.',
    'error.email_change.invalid_token' =>
        'That confirmation link has expired or has already been used.',
    'error.invite.invalid_token' => 'That invitation has expired or has already been used.',
    'error.member.last_owner' =>
        'This is the household\'s last Owner. Make somebody else an Owner first — a household with '
        . 'nobody able to administer it cannot be put right from inside.',
    'error.member.no_mailbox' => 'That member has no email address, so nothing can be sent to them.',
    'error.member.not_found' => 'That person is not a member of this household.',
    'error.member.not_your_own_role' =>
        'You cannot change your own role. Ask another Owner to change it for you.',
    'error.member.not_yourself' => 'You cannot do that to your own account.',
    'error.member.invite_as_owner' =>
        'Invite them as an Editor, Contributor or Viewer, and make them an Owner once they have joined.',
    'error.member.removal_choice_required' =>
        'Choose what should happen to the subscriptions nobody else has seen.',
    'error.member.role_invalid' => 'Choose a role from the list.',
    'error.offer.change_announced' =>
        'A price change is already announced before then, so the offer would end there. Choose an earlier date, or leave it blank.',
    'error.offer.ends_past' => 'The offer has to end after today, and after the price starts.',
    'error.offer.then_required' => 'Enter what it costs once the offer ends.',
    'error.palette.unknown' => 'Choose one of the palettes shown.',
    'flash.avatar_missing' => 'There was no picture to remove.',
    'flash.avatar_removed' => 'Your picture has been removed.',
    'flash.avatar_saved' => 'Your picture has been saved.',
    'flash.email_change_cancelled' => 'The pending email change has been cancelled.',
    'flash.email_change_requested' =>
        'Check the new address for a confirmation link. Until you follow it, your current address '
        . 'stays your sign-in.',
    'flash.invite_accepted' => 'Your account is ready. Sign in with the password you just chose.',
    'flash.member_added_temporary' => 'The account has been created. Give them the password below.',
    'flash.member_invite_resent' => 'The invitation has been sent again.',
    'flash.member_invited' => 'An invitation is on its way.',
    'flash.member_removed' => 'They are no longer a member of this household.',
    'flash.member_reset_sent' => 'A password reset link has been sent to them.',
    'flash.member_restored' => 'They can sign in again.',
    'flash.member_revoked' =>
        'Their login has been revoked and they have been signed out everywhere.',
    'flash.member_role_changed' => 'Their role has been changed.',
    'flash.password_changed_sessions' =>
        'Your password has been changed, and {count, plural, one {# other session was} other {# other '
        . 'sessions were}} signed out.',
    'mail.email_change.body' =>
        "Hello {name},\n\nConfirm that you can read mail at this address, and it becomes the one "
        . "you sign in with:\n\n{link}\n\nThe link is valid for one hour. Until you follow it, "
        . 'nothing changes. If you did not ask for this, you can ignore this message.',
    'mail.email_change.subject' => 'Confirm your new {instance} address',
    'mail.email_change_notice.body' =>
        "Hello {name},\n\nSomebody asked to move your {instance} account to {new_email}. Nothing "
        . 'has changed yet: this address is still your sign-in until the new one is '
        . "confirmed.\n\nIf that was not you, sign in and change your password now.",
    'mail.email_change_notice.subject' => 'A change of address was requested on {instance}',
    'mail.invite.body' =>
        "Hello {name},\n\n{inviter} has added you to their household on {instance}, a shared "
        . "subscription tracker.\n\nChoose a password and you are in:\n\n{link}\n\nThe link is "
        . 'valid for {days, plural, one {# day} other {# days}}. If you were not expecting this, you '
        . 'can ignore this message.',
    'mail.invite.subject' => '{inviter} has invited you to {instance}',
    'members.actions' => 'Actions',
    'members.email_placeholder' => 'name@example.com',
    'members.figures_withheld_note' =>
        'A dash is a figure that is not yours to see: other members\' spending is shown only where '
        . 'you can see all of it.',
    'members.intro' =>
        'Everyone in {household} and what their role lets them do. Only Owners can '
        . 'change roles, invite people or remove them.',
    'members.invite' => 'Invite member',
    'members.invite_expiry' =>
        'They\'ll get an email link that expires in {count, plural, one {# day} other {# days}}.',
    'members.invite_title' => 'Invite to {household}',
    'members.last_seen' => 'Last seen',
    'members.matrix_caption' => '"Own only" means only rows that member pays for.',
    'members.matrix_heading' => 'What each role can do',
    'members.matrix_permission' => 'Permission',
    'members.monthly_share' => 'Monthly share',
    'members.never_signed_in' => 'Never',
    'members.no_mailbox' => 'No email address',
    'members.remove' => 'Remove',
    'members.remove_account_kept' =>
        'Their account itself is not deleted — this removes their membership of this household.',
    'members.remove_confirm' => 'Remove from household',
    'members.remove_data_delete' =>
        'Delete them, along with their price history, attachments and budgets. This cannot be undone.',
    'members.remove_data_legend' =>
        'What should happen to the {count, plural, one {# subscription} other {# subscriptions}} they '
        . 'own?',
    'members.remove_data_reassign' => 'Give them to me. Nothing is lost, and I become their owner.',
    'members.remove_data_shared' =>
        'Anything they own stays with the household and becomes yours. Their share of any split cost '
        . 'is removed.',
    'members.remove_heading' => 'Remove {name}?',
    'members.remove_intro' =>
        '{name} will lose access to this household immediately, and will be signed out everywhere.',
    'members.remove_private_delete' => 'Delete them, with their price history and documents.',
    'members.remove_private_legend' =>
        'They keep {count, plural, one {# subscription} other {# subscriptions}} to themselves, which '
        . 'nobody else has seen. What should happen to {count, plural, one {it} other {them}}?',
    'members.remove_private_reassign' => 'Give them to me. They stay private — to me.',
    'members.resend_invite_for' => 'Resend invite to {name}',
    'members.resend_invite' => 'Resend invite',
    'members.role_description_contributor' =>
        'Adds and edits their own subscriptions, prices, splits and budget.',
    'members.role_description_editor' => 'Can change anything the household has.',
    'members.role_description_viewer' => 'Sees subscriptions and totals. Changes nothing.',
    'members.restore_login_for' => 'Restore login for {name}',
    'members.restore_login' => 'Restore login',
    'members.revoke_login_for' => 'Revoke login for {name}',
    'members.revoke_login' => 'Revoke login',
    'members.send_invitation' => 'Send invitation',
    'members.send_reset_for' => 'Send reset to {name}',
    'members.send_reset' => 'Send reset',
    'members.subtitle' => '{household} · {count, plural, one {# person} other {# people}}',
    'members.table_heading' => 'Members',
    'members.temporary_password_heading' => 'Their temporary password',
    'members.temporary_password_note' =>
        'Give this to {name}. They will be asked to replace it as soon as they sign in.',
    'members.temporary_password_once' =>
        'This is the only time it is shown. Renovo keeps only a hash of it, so it cannot be looked '
        . 'up again — if it is lost, add nothing and simply issue a new one.',
    'members.this_household' => 'This household',
    'members.visibility_change' => 'Change it in instance settings',
    'members.visibility_heading' => 'Data visibility',
    'members.visibility_instance' => 'An instance administrator sets this for every household on the instance.',
    'members.visibility_mode_isolated' => 'Isolated',
    'members.visibility_mode_shared' => 'Shared',
    'members.visibility_note_isolated' =>
        'Each member of {household} sees only the subscriptions they pay for or share '
        . 'the cost of, and changes only their own. That holds for every role, Owners included.',
    'members.visibility_note_shared' =>
        'Everyone in {household} sees all of its subscriptions and what they cost. '
        . 'What each person may change is set by their role.',
    'members.visibility_private' =>
        'A subscription set to "Only me" is hidden from everyone else in either mode, and its cost '
        . 'is left out of their totals.',
    'members.without_email' => 'This member has no email address',
    'members.without_email_hint' =>
        'For a child with no mailbox. Renovo creates the account with a one-time password shown to '
        . 'you once, which they must replace the first time they sign in.',
    'members.you' => 'you',
    'membership_status.active' => 'Active',
    'membership_status.pending' => 'Invite pending',
    'membership_status.revoked' => 'Login revoked',
    'type.recurring' => 'Recurring',
    'type.one_off' => 'One-off',
    'type.lifetime' => 'Lifetime',

    'role.owner_admin' => 'Owner / Admin',
    'role.editor' => 'Editor',
    'role.contributor' => 'Contributor',
    'role.viewer' => 'Viewer',

    'isolation.shared' => 'Shared — everyone in a household sees its subscriptions',
    'isolation.isolated' => 'Isolated — each member sees only their own',

    'price_change.initial' => 'Starting price',
    'price_change.manual' => 'Price changed',
    'price_change.offer_ended' => 'Offer ended',
    'price_change.offer_ends' => 'Offer ends',
    'price_change.scheduled' => 'Scheduled change',
    'price_change.trial_conversion' => 'Free trial ended',
    'price_change.currency_change' => 'Currency converted',
    'price_change.unknown' => 'Price',

    'charge.status.charged' => 'Charged',
    'charge.status.skipped' => 'Skipped',
    'charge.source.manual' => 'added by hand',
    'charge.source.reconstructed' => 'reconstructed',
    'charge.source.paused' => 'paused',

    'payments.against_estimate' => 'estimate {estimate}',
    'payments.estimate_average' =>
        'The last {count, plural, one {bill was} other {# bills averaged}} {average}, against an estimate of '
        . '{estimate}.',
    'payments.use_as_estimate' => 'Use as estimate',
    'payments.term_amount' => '{paid} of {total} paid',
    'payments.term_count' => 'Payment {paid} of {total}',
    'payments.term_ends' => 'last payment by {date}',
    'payments.heading' => 'Payments',
    'payments.note' => 'One row for each billing date that has passed, at the price due that day. Record what was really charged, or mark a charge that did not happen.',
    'payments.empty' => 'No payments yet. A row appears here once a billing date has passed.',
    'payments.status' => 'Status',
    'payments.invoice' => 'Invoice',
    'payments.note_column' => 'Note',
    'payments.recorded' => 'Recorded',
    'payments.expected' => 'Expected',
    'payments.charged_as' => 'Charged',
    'payments.change' => 'Change',
    'payments.actual_amount' => 'Amount actually charged',
    'payments.record_amount' => 'Record amount',
    'payments.mark_skipped' => 'Mark as skipped',
    'payments.undo' => 'Undo',
    'payments.remove' => 'Remove payment',
    'payments.attach_invoice' => 'Attach the invoice for this payment',
    'payments.pages' => 'Payment pages',
    'payments.add' => 'Add a payment',
    'payments.add_hint' => 'For a charge that fell off the cycle, such as a one-time fee. Today or earlier.',
    'payments.add_submit' => 'Add payment',
    'payments.amount_in' => 'Amount ({currency})',
    'payments.normally' => '(normally {amount})',
    'payments.discounted' => '(discount)',
    'payments.was_discount' => 'This was a discount',
    'payments.was_discount_hint' => 'Counts what you saved against the {expected} that was due.',
    'confirm.remove_payment' => 'Remove this payment?',
    'flash.estimate_updated' => 'Estimate updated to the average of the latest bills.',
    'flash.charge_added' => 'Payment added.',
    'flash.charge_recorded' => 'Amount recorded.',
    'flash.charge_skipped' => 'Payment marked as skipped.',
    'flash.charge_undone' => 'Payment put back as it was.',
    'flash.charge_removed' => 'Payment removed.',
    'error.charge.not_found' => 'That payment could not be found.',
    'error.charge.actual_required' => 'Enter the amount that was charged.',
    'error.charge.amount_required' => 'Enter the amount of the payment.',
    'error.charge.date_required' => 'Enter the date of the payment.',
    'error.charge.date_future' => 'A payment is dated today or earlier; one still to come is in the forecast.',
    'error.charge.date_taken' => 'There is already a payment on that date.',
    'error.charge.one_off_date_taken' =>
        'There is already a payment on that date. Change or remove it on the Payments card first, or choose another date.',
    'error.charge.status_invalid' => 'The status must be charged or skipped.',
    'error.charge.actual_on_skipped' => 'A skipped payment has no amount.',
    'error.charge.discount_not_lower' => 'A discount is an amount below the price that was due.',
    'error.charge.discount_on_offer' => 'This payment was on an offer, which already counts what it saved.',
    'error.charge.discount_invalid' => 'is_discount must be true or false.',
    'error.charge.minor_invalid' => 'Send a whole number of minor units, 0 or more: 999 for 9.99.',
    // promo — an introductory price, beside the price it is
    'promo.chip' => 'Promo',
    'promo.chip_until' => 'Promo until {date}',
    'promo.heading' => 'Intro offer',
    'promo.no_end' => 'No end date yet — add one on the edit page so you’re warned before the price goes up.',
    'promo.on_price' => 'On an introductory price of',
    'promo.then' => 'From {date} it costs',

    'split.none' => 'Not split',
    'split.equal' => 'Split equally',
    'split.custom' => 'Split by share',

    'budget_period.monthly' => 'Monthly',
    'budget_period.annual' => 'Yearly',

    'notice.none' => 'None',
    'notice.days' => '{count, plural, one {# day} other {# days}}',
    'notice.weeks' => '{count, plural, one {# week} other {# weeks}}',
    'notice.months' => '{count, plural, one {# month} other {# months}}',

    'alert.price_change' => 'Price change',
    'alert.price_change.line' => '{old} → {new} from {date}',
    'alert.price_change.offer_line' => 'Intro offer ends on {date} — then {new} ({difference})',
    'alert.price_change.title_fall' => '{name} is going down',
    'alert.price_change.title_offer_ends' => '{name}: intro offer ends',
    'alert.price_change.title_rise' => '{name} is going up',
    'alert.price_change.yearly' => '{amount} a year',
    'alert.renewal' => 'Upcoming renewal',
    'alert.trial_conversion' => 'Trial about to convert',
    'alert.cancel_by' => 'Cancellation deadline',
    'alert.budget_exceeded' => 'Budget projected to be exceeded',
    'alert.about' => 'about {amount}',
    'alert.contract_ending' => 'Contract ending',
    'alert.contract.line' =>
        'Your {name} contract ends on {date} — a good time to compare prices or renegotiate.',
    'alert.contract.title' => '{name}: contract ends {when}',

    // -----------------------------------------------------------------------
    // Display preferences
    // -----------------------------------------------------------------------
    'settings.theme' => 'Theme',
    'settings.language' => 'Language',
    'settings.language_instance_default' => 'Whatever this instance is set to',
    'settings.week_start' => 'Weeks start on',
    'settings.week_start_hint' => 'Used by the calendar.',
    'settings.density' => 'List density',
    'settings.landing_view' => 'Open on',
    'settings.landing_view_hint' => 'The page you see when you open Renovo.',

    'settings.palette' => 'Colour palette',
    'settings.palette_hint' => 'The colours of the sidebar and of the buttons and highlights. Only you see your choice.',

    'palette.navy' => 'Navy & emerald',
    'palette.paper' => 'Light & emerald',
    'palette.midnight' => 'Midnight & teal',
    'palette.ocean' => 'Light & ocean blue',
    'palette.forest' => 'Forest & mint',

    'theme.system' => 'Auto',
    // The same choice on the sign-in screen's switch, where the three options
    // sit side by side and a sentence would not fit beside two single words.
    'theme.system_short' => 'Auto',
    'theme.light' => 'Light',
    'theme.dark' => 'Dark',

    'density.comfortable' => 'Comfortable',
    'density.compact' => 'Compact',

    'week_start.sunday' => 'Sunday',
    'week_start.monday' => 'Monday',

    // -----------------------------------------------------------------------
    // Error pages and the API's error envelope
    // -----------------------------------------------------------------------
    'error.page.unexpected_title' => 'Something went wrong',
    'error.page.unexpected_message' => 'An unexpected error occurred. The details have been logged.',
    'error.page.validation_title' => 'Validation failed',
    'error.page.validation_message' => 'The submitted data is not valid.',
    'error.page.not_found_title' => 'Not found',
    'error.page.not_found_message' => 'That page does not exist.',
    'error.page.not_found_or_forbidden' => 'That page does not exist, or you do not have access to it.',
    'error.page.forbidden_title' => 'Not allowed',
    'error.permission.denied' => 'Your role does not permit "{permission}".',
    'error.page.forbidden_message' => 'Your role does not permit that action.',
    'error.page.method_title' => 'Method not allowed',
    'error.page.rate_limited_title' => 'Too many requests',
    'error.page.method_message' => 'That address does not accept this kind of request.',

    // -----------------------------------------------------------------------
    // Validation messages. Raised by services as keys and resolved by whoever
    // is rendering — a Twig form, or the API's error envelope.
    // -----------------------------------------------------------------------
    'error.plan.too_long_60' => 'Keep the plan to 60 characters.',
    'error.split.private' =>
        'Only you can see this subscription, so it cannot be split. Make it visible to the household first.',
    'error.subscription.cancelled_resume' =>
        'A cancelled subscription cannot be resumed. Undo the cancellation first; it comes back paused.',
    'error.subscription.not_found' => 'That subscription does not exist.',
    'error.currency.required' => 'Choose a currency.',
    'error.member.not_in_household' => 'Choose a member of this household.',
    'error.member.required' => 'Choose a member.',
    'error.category.not_found' => 'That category does not exist.',
    'error.price.negative' => 'Enter a price of zero or more.',
    'error.price.invalid' => 'Enter a price, for example 9.99.',
    'error.price.too_large' => 'Enter a smaller price.',
    'error.amount.negative' => 'Enter an amount of zero or more.',
    'error.amount.invalid' => 'Enter an amount, for example 150.00.',
    'error.email.invalid' => 'Enter a valid email address.',
    'error.email.taken' => 'An account with that email address already exists.',
    'error.name.required' => 'Enter a name.',
    'error.name.required_yours' => 'Enter your name.',
    'error.name.too_long_60' => 'Name must be 60 characters or fewer.',
    'error.name.too_long_100' => 'Name must be 100 characters or fewer.',
    'error.name.too_long_150' => 'Name must be 150 characters or fewer.',
    'error.note.too_long_255' => 'Note must be 255 characters or fewer.',
    'error.notes.too_long_5000' => 'Notes must be 5000 characters or fewer.',
    'error.date.invalid' => 'Enter a valid date.',
    'error.estimate.nothing_to_use' => 'There is no different average of recorded bills to use yet.',
    'error.payments_end.before_next' => 'The last payment can’t come before the next one.',
    'error.payments_end.no_billing_date' =>
        'The last payment would be before the first billing date from today, so this can’t be resumed. Move the last payment date later first.',
    'error.json.wrong_type' => 'Send this as text, not as true, false, a list or an object.',
    'error.payments_end.before_start' => 'The last payment can’t come before the start date.',
    'error.file.required' => 'Choose a file to upload.',
    'error.upload.failed' => 'The file could not be uploaded. Try again.',

    'error.password.incorrect' => 'That password is not correct.',
    'error.password.too_long' => 'That password is too long.',
    'error.password.mismatch' => 'The two passwords do not match.',
    'error.password.too_short' =>
        'Use at least {count, plural, one {# character} other {# characters}}.',
    'error.recovery_code.invalid' => 'That recovery code is not valid, or has already been used.',
    'error.totp.code_wrong' => 'That code is not correct.',
    'error.auth.credentials' => 'That email and password don’t match an account.',
    'error.auth.unverified' => 'Confirm your email address before signing in. Check your inbox for the link.',
    'error.auth.throttled' =>
        'Too many attempts. Try again in {minutes, plural, one {# minute} other {# minutes}}.',
    'error.verify.throttled' =>
        'Too many requests for a new link. Try again in {minutes, plural, one {# minute} other {# minutes}}.',
    'error.setup.household_name_required' => 'Give the household a name.',
    'error.setup.invites_invalid' => 'These cannot be invited: {addresses}. Check each is an address nobody here uses yet.',
    'error.setup.invites_too_many' => 'Invite at most {max} people here; add the rest from Members & roles.',
    'error.reset.throttled' =>
        'Too many reset requests. Try again in {minutes, plural, one {# minute} other {# minutes}}.',
    'error.reset.invalid_token' => 'That reset link has expired or has already been used.',

    'error.totp.no_pending' => 'Start the setup again — no pending enrolment was found.',
    'error.totp.already_set_up' => 'An authenticator app is already set up for this account.',
    'error.totp.setup_unreadable' =>
        'This setup can no longer be completed. Start it again to get a new secret.',
    'error.totp.code_incorrect' => 'That code is not correct. Check the clock on your device.',
    'error.two_factor.none_set_up' =>
        'There is no second factor set up for this account, so there is nothing to recover.',
    'error.passkey.not_registration' => 'That is not a registration response.',
    'error.passkey.registration_failed' => 'That security key could not be registered: {reason}',
    'error.passkey.verification_failed' => 'That key could not be verified: {reason}',
    'error.passkey.already_registered' => 'That key is already registered.',
    'error.passkey.not_authentication' => 'That is not an authentication response.',
    'error.passkey.unknown' => 'That key is not registered on this instance.',
    'error.passkey.other_account' => 'That key belongs to a different account.',
    'error.passkey.missing' => 'That passkey no longer exists.',
    'error.passkey.unreadable' => 'The browser sent a response we could not read.',

    'error.url.required' => 'Enter a URL.',
    'error.visibility.invalid' => 'Choose who can see this subscription.',
    'error.visibility.owner_only' => 'Only a subscription that belongs to you can be kept to yourself.',
    'error.visibility.payer_is_owner' =>
        'A subscription kept to yourself is paid by you. Clear "Paid by" or choose yourself.',
    'error.visibility.split' =>
        'This subscription is split, and a shared cost is always visible to the people sharing it. '
        . 'Remove the split first.',
    'error.website.too_long' => 'That address is too long.',
    'error.url.invalid' => 'Enter a valid URL, including https://.',
    'error.url.scheme' => 'The URL must start with https:// or http://.',
    'error.url.no_host' => 'The URL is missing a host name.',
    'error.gotify.token_required' => 'Enter the Gotify application token.',
    'error.gotify.priority_range' => 'Enter a priority between 0 and 10.',
    'error.slack.token_required' => 'Enter the Slack bot token.',
    'error.slack.channel_required' => 'Enter the channel or user to message.',
    'error.slack.channel_invalid' => 'That does not look like a channel or user id.',
    'error.discord.https_required' => 'A Discord webhook URL must start with https://.',
    'error.discord.url_invalid' =>
        'That is not a Discord webhook URL. Copy it from Server Settings → Integrations → Webhooks.',
    'error.mattermost.url_invalid' =>
        'That is not a Mattermost webhook URL — it should contain /hooks/.',
    'error.mattermost.channel_invalid' => 'Use a channel name such as bills, or @username for a direct message.',
    'error.ntfy.topic_required' => 'Enter the topic to publish to.',
    'error.ntfy.topic_invalid' => 'A topic may use letters, numbers, dashes and underscores only.',
    'error.ntfy.priority_range' => 'Enter a priority between 1 and 5.',
    'error.ntfy.tags_invalid' => 'Enter tags separated by commas, using letters, numbers, dashes and underscores.',
    'error.pushover.token_required' => 'Enter the Pushover application token.',
    'error.pushover.token_invalid' => 'A Pushover application token is 30 letters and numbers.',
    'error.pushover.user_key_required' => 'Enter your Pushover user or group key.',
    'error.pushover.user_key_invalid' => 'A Pushover user key is 30 letters and numbers.',
    'error.pushover.priority_range' =>
        'Enter a priority between -2 and 1. Emergency priority is not supported.',
    'error.pushplus.token_required' => 'Enter the Pushplus token.',
    'error.pushplus.token_invalid' => 'A Pushplus token is 32 characters.',
    'error.pushplus.topic_invalid' => 'That topic code is too long.',
    'error.serverchan.sendkey_required' => 'Enter your Server酱 SendKey.',
    'error.serverchan.sendkey_invalid' => 'A Turbo SendKey starts with SCT followed by letters and numbers.',
    'error.telegram.token_required' => 'Enter the Telegram bot token.',
    'error.telegram.token_invalid' => 'A bot token looks like 123456789:AA... — copy it from BotFather.',
    'error.telegram.chat_id_required' => 'Enter the chat to message.',
    'error.telegram.chat_id_invalid' =>
        'Use a numeric chat id, or @name for a public channel.',
    'error.channel.type_required' => 'Choose a channel type.',
    'error.channel.missing' => 'That channel no longer exists.',
    'error.channel.type_unavailable' => 'That channel type is no longer available.',
    'error.channel.unreadable' =>
        "This channel's settings can't be read with the current encryption key. Ask your administrator to "
        . 'restore the old key, or delete the channel and add it again.',
    'error.digest.weekday' => 'Choose a day of the week.',
    'error.demo.read_only' =>
        'This instance is a read-only demonstration. Nothing here can be changed.',
    'error.digest.monthday' => 'Choose a day of the month between 1 and 28.',
    'error.lead_days.invalid' => 'Enter whole numbers of days, separated by commas — for example 30, 7, 1.',
    'error.lead_days.too_many' =>
        'Use at most {max, plural, one {# reminder} other {# reminders}} per subscription.',

    'error.token.name_required' => 'Give the token a name so you can recognise it later.',
    'error.token.expiry_past' => 'Choose an expiry date in the future.',
    'error.token.not_found' => 'That token no longer exists.',
    'error.token.not_reissuable' =>
        'Only a token that still works can be reissued. This one has been revoked or has expired — '
        . 'create a new one instead.',
    'error.token.scopes_required' => 'Choose at least one thing the token may reach.',
    'error.token.expiry_required' =>
        'This instance allows tokens for at most {days, plural, one {# day} other {# days}}. Choose when it expires.',
    'error.token.expiry_too_far' =>
        'This instance allows tokens for at most {days, plural, one {# day} other {# days}}. Choose an earlier date.',
    'error.token.expiry_date_required' => 'Choose the date the token expires, or No expiry.',

    'error.attachment.type' => 'Upload a PDF, PNG, JPEG, WebP or GIF file.',
    'error.attachment.archive_type' => 'The archive contains a file of an unsupported type.',
    'error.attachment.too_large' => 'The file must be {megabytes} MB or smaller.',
    'error.logo.upload_failed' => 'The logo could not be uploaded. Try again.',
    'error.logo.too_large' => 'The logo must be {kilobytes} KB or smaller.',
    'error.logo.type' => 'Upload a PNG, JPEG, GIF or WebP image.',

    'error.backup.not_zip' => 'That file is not a readable ZIP archive.',
    'error.backup.foreign' => 'That archive was not produced by Renovo.',
    'error.backup.too_many_files' => 'That archive contains too many files.',
    'error.backup.unexpected_file' => 'That archive contains an unexpected file and was not restored.',
    'error.backup.missing_entry' => 'That archive has no {entry}.',
    'error.backup.invalid_json' => 'The {entry} in that archive is not valid JSON.',

    'error.import.empty' => 'That file is empty.',
    'error.import.unreadable' => 'That file could not be read.',
    'error.import.no_header' => 'That file has no header row.',
    'error.import.invalid_json' => 'That file is not valid JSON.',
    'error.import.no_subscriptions' => 'That file contains no subscriptions.',
    'error.import.file_required' => 'Choose a CSV or JSON file to import.',
    'error.import.too_large' => 'The file must be {megabytes} MB or smaller.',
    'error.import.too_large_absolute' => 'That file is too large to import.',
    'error.import.expired' => 'That upload has expired. Upload the file again.',
    'error.import.too_many_rows' => 'That file has more than {max} rows. Split it and import the parts separately.',
    'error.import.too_many_entries' =>
        'That file has more than {max} entries. Split it and import the parts separately.',

    'error.budget.name_required' => 'Give the budget a name.',
    'error.budget.period' => 'Choose monthly or annual.',
    'error.budget.threshold_range' => 'Enter a warning threshold between 1 and 100 percent.',

    'error.bulk.no_subscriptions' => 'Choose at least one subscription.',
    'error.bulk.no_action' => 'Choose an action to apply.',
    'error.bulk.isolated_reassign' =>
        "Ownership cannot be reassigned while the instance keeps members' data separate.",
    'error.bulk.no_rate' =>
        'No exchange rate is available between {from} and {to}, so "{name}" cannot be converted.',
    'error.tag.required' => 'Enter a tag.',

    'error.category.name_required' => 'Enter a category name.',
    'error.category.duplicate' => 'A category with that name already exists.',
    'error.category.colour_required' => 'Choose a colour.',

    'error.payment_method.not_found' => 'That payment method does not exist.',
    'error.payment_method.name_required' => 'Enter a name for the payment method.',
    'error.payment_method.duplicate' => 'A payment method with that name already exists.',
    'error.price_change.date_required' => 'Enter the date the new price starts.',
    'error.price_change.date_past' => 'Choose a future date. To change the price now, edit the subscription.',

    'error.saved_view.name_required' => 'Give the view a name so you can recognise it later.',
    'error.saved_view.too_many' => 'You already have {max} saved views. Forget one before saving another.',
    'error.split.owner_only' => 'Only the member who owns this subscription can change how it is split.',
    'error.split.share_range' => 'Shares must be between 0 and 10000.',
    'error.split.no_members' => 'Choose at least one member to share the cost.',

    'error.trial.end_date_required' => 'Enter the date the trial ends.',
    'error.trial.converts_to_invalid' => 'Enter the price it converts to, for example 9.99.',
    'error.cycle.required' => 'Choose a billing cycle.',
    'error.cycle_days.range' => 'Enter the number of days between payments (1–3650).',
    'error.cycle_interval.weekly' => 'Choose every 1 to 52 weeks.',
    'error.cycle_interval.monthly' => 'Choose every 1 to 24 months.',
    'error.cycle_interval.yearly' => 'Choose every 1 to 10 years.',
    'error.cycle_interval.not_allowed' => 'Quarterly and custom-day cycles take no interval.',
    'error.anchor_last_day.not_month_end' => 'To bill on the last day of the month, pick a next payment date that is a month’s last day.',
    'error.reminder_days.invalid' => 'Enter days as whole numbers, for example 30, 7, 1 — or "none".',
    'error.reminder_days.too_many' => 'Use at most six reminders for one subscription.',
    'error.next_payment.required' => 'Enter the next payment date.',
    'error.notice.range' => 'Enter a notice period between 0 and 3650.',
    'error.notice.unit_required' => 'Choose a notice period unit.',

    'error.trusted_host.required' => 'Enter a host name, address or CIDR range.',
    'error.trusted_host.too_long' => 'That is too long to be a host or range.',
    'error.trusted_host.invalid' =>
        'Enter a host name (gotify.lan), a suffix (.lan), an address (192.168.1.10) or a range '
        . '(100.64.0.0/10).',
    'error.trusted_host.duplicate' => 'That is already on the list.',

    // -----------------------------------------------------------------------
    // Flash messages. Queued by the request that acted and rendered by the
    // next one, so what is stored is the key and its arguments.
    // -----------------------------------------------------------------------
    'flash.raw' => '{message}',
    'flash.subscription_cancelled' => 'Subscription cancelled.',
    'flash.subscription_uncancelled' =>
        'Cancellation undone. The subscription is paused until you resume it.',
    'flash.welcome_back' => 'Welcome back, {name}.',
    'flash.signed_out' => 'You have been signed out.',
    'flash.password_changed' => 'Your password has been changed. Sign in with it now.',
    'flash.two_factor_expired' => 'That sign-in attempt expired. Start again.',
    'flash.dashboard_layout_reset' => 'This dashboard is back to its default layout.',
    'flash.dashboard_layout_saved' => 'Your dashboard layout has been saved.',
    'flash.preferences_saved' => 'Your preferences have been saved.',
    'flash.details_saved' => 'Your details have been saved.',
    'flash.email_change_resent' =>
        'A new confirmation link is on its way to the new address. The earlier link no longer works.',

    'flash.subscription_added' => 'Subscription added.',
    'flash.subscription_saved' => 'Subscription saved.',
    'flash.subscription_deleted' => 'Subscription deleted.',
    'flash.subscription_paused' => 'Subscription paused.',
    'flash.subscription_resumed' => 'Subscription resumed.',
    'flash.bulk_updated' => '{count, plural, one {# subscription} other {# subscriptions}} updated.',
    'flash.bulk_tags_full' =>
        '{count, plural, one {# subscription was left as it is} other {# subscriptions were left as they are}}'
        . ' because it already has 25 tags.',
    'flash.price_change_scheduled' => 'Price change scheduled.',
    'flash.split_updated' => 'Cost split updated.',
    'flash.usage_reset' => 'Usage count reset.',

    'flash.budget_created' => 'Budget created.',
    'flash.budget_saved' => 'Budget saved.',
    'flash.budget_deleted' => 'Budget deleted.',

    'flash.category_added' => 'Category added.',
    'flash.category_renamed' => 'Category renamed.',
    'flash.category_deleted' => 'Category deleted.',
    'flash.payment_method_added' => 'Payment method added.',
    'flash.payment_method_saved' => 'Payment method saved.',
    'flash.payment_method_logo_cleared' => 'Logo removed.',
    'flash.payment_method_deleted' => 'Payment method deleted. Its subscriptions no longer say how they are paid.',
    'flash.payment_methods_defaults_added' => 'The default payment methods have been added.',
    'flash.tag_deleted' => 'Tag deleted.',

    'flash.channel_added' => 'Notification channel added. Send a test message to check it works.',
    'flash.channel_updated' => 'Channel updated.',
    'flash.channel_removed' => 'Channel removed.',
    'flash.test_message_sent' => 'Test message sent to {label}.',
    'flash.notification_preferences_saved' => 'Notification preferences saved.',

    'flash.totp_enabled' => 'Two-factor authentication is on. Save your recovery codes now.',
    'flash.totp_disabled' => 'Two-factor authentication is off.',
    'flash.recovery_codes_issued' => 'New recovery codes issued. The old ones no longer work.',
    'flash.passkey_added' => 'Passkey "{name}" added.',
    'flash.passkey_renamed' => 'Passkey renamed.',
    'flash.passkey_revoked' => 'Passkey revoked.',
    'flash.saved_view_created' => 'View saved.',
    'flash.saved_view_deleted' => 'View forgotten.',
    'flash.saved_view_missing' => 'That view no longer exists.',
    'flash.session_revoked' => 'That session was signed out.',
    'flash.session_already_gone' => 'That session is no longer active.',
    'flash.other_sessions_revoked' =>
        '{count, plural, =0 {No other sessions were signed in.} one {# other session signed out.}'
        . ' other {# other sessions signed out.}}',

    'flash.feed_link_created' => 'A new calendar feed link was created. Copy it now — it is not shown again.',
    'flash.token_created' => 'Token created. Copy it now — it is not shown again.',
    'flash.token_reissued' =>
        'Token reissued. The previous one has stopped working — copy the new one now, it is not shown again.',
    'flash.token_revoked' => 'Token revoked.',
    'flash.token_revoke_failed' => 'That token could not be revoked.',

    'flash.attachment_required' => 'Choose a file to attach.',
    'flash.attachment_uploaded' => 'Attachment uploaded.',
    'flash.attachment_deleted' => 'Attachment deleted.',
    'flash.attachment_missing' => 'No such attachment.',

    'flash.import_finished' => 'Imported {count, plural, one {# subscription} other {# subscriptions}}.',
    'flash.import_skipped' => '{count, plural, one {# row was} other {# rows were}} skipped.',
    'flash.backup_failed' => 'The backup could not be prepared.',
    'flash.backup_file_required' => 'Choose a backup archive to restore.',
    'flash.backup_unreadable' => 'The archive could not be read.',
    'flash.restore_finished' =>
        'Restored {subscriptions, plural, one {# subscription} other {# subscriptions}},'
        . ' {categories, plural, one {# category} other {# categories}},'
        . ' {payment_methods, plural, one {# payment method} other {# payment methods}},'
        . ' {tags, plural, one {# tag} other {# tags}},'
        . ' {budgets, plural, one {# budget} other {# budgets}},'
        . ' {attachments, plural, one {# attachment} other {# attachments}}'
        . ' and {charges, plural, one {# payment} other {# payments}}.',
    'flash.restore_tags_trimmed' =>
        '{count, plural, one {# subscription had} other {# subscriptions had}} more than 25 tags, so only the first 25 were restored.',
    'flash.restore_skipped' => '{count, plural, one {# item was} other {# items were}} skipped.',

    'flash.household_saved' => 'Household settings saved.',
    'flash.instance_saved' => 'Instance settings saved.',
    'flash.trusted_host_added' => 'Trusted host added. Notifications may now reach it.',
    'flash.trusted_host_removed' => 'Trusted host removed.',
    'flash.outbound_off' => 'Outbound requests are turned off on this instance.',
    'flash.outbound_not_allowlisted' => 'That host is not on this instance\'s allowed list for outbound requests.',

    'flash.setup_channel_added' => 'Channel added. Send yourself a test message to confirm it arrives.',
    'flash.setup_test_email_off' => 'Turn Email on to send a test message.',
    'flash.setup_test_email_sent' => 'A test message is on its way to {address}.',

    // -----------------------------------------------------------------------
    // Notifications
    // -----------------------------------------------------------------------
    'digest.section' => '{label}:',
    'digest.title.weekly' =>
        'Weekly subscription summary: {count, plural, one {# thing to know} other {# things to know}}',
    'digest.title.monthly' =>
        'Monthly subscription summary: {count, plural, one {# thing to know} other {# things to know}}',

    'landing.dashboard' => 'Dashboard',
    'landing.subscriptions' => 'Subscriptions',
    'landing.calendar' => 'Calendar',
    'landing.budgets' => 'Budgets',
    'landing.forecast' => 'Forecast',
    'landing.stats' => 'Statistics',

    // -----------------------------------------------------------------------
    // The interface itself, grouped by the page each string appears on.
    // `action.*`, `field.*`, `state.*` and `nav.*` are shared across pages:
    // "Save" is one string in this application, not eleven.
    //
    // `js.*` is the group handed to the browser by js_translations(); those
    // messages take named arguments and never plurals, because the formatter
    // on that side is three lines long and deliberately stays that way.
    // -----------------------------------------------------------------------

    // action
    'action.apply' => 'Apply',
    'action.back_to_sign_in' => 'Back to sign in',
    'action.cancel' => 'Cancel',
    'action.close' => 'Close',
    'action.back' => 'Back',
    'action.continue' => 'Continue',
    'action.delete' => 'Delete',
    'action.edit' => 'Edit',
    'action.newer' => 'Newer',
    'action.next' => 'Next',
    'action.older' => 'Older',
    'action.preview' => 'Preview',
    'action.previous' => 'Previous',
    'action.remove' => 'Remove',
    'action.rename' => 'Rename',
    'action.reissue' => 'Reissue',
    'action.revoke' => 'Revoke',
    'action.save' => 'Save',
    'action.send_test' => 'Send test',
    'action.upload' => 'Upload',

    // audit
    'audit.all_events' => 'All events',
    'audit.filter' => 'Filter',
    'audit.retention_note' => 'Entries older than the configured retention period are removed by the nightly prune.',
    'audit.scope_household' => 'Security-relevant events in your household.',
    'audit.scope_instance' => 'Every security-relevant event on this instance.',
    'audit.title' => 'Audit log',

    // audit_action
    'audit_action.api_token.issued' => 'API token issued',
    'audit_action.api_token.revoked' => 'API token revoked',
    'audit_action.api_token.auth_failed' => 'API token refused',
    'audit_action.attachment.deleted' => 'Attachment deleted',
    'audit_action.attachment.uploaded' => 'Attachment uploaded',
    'audit_action.backup.exported' => 'Backup exported',
    'audit_action.backup.restored' => 'Backup restored',
    'audit_action.subscription.ended' => 'Ended — final payment made',
    'audit_action.data.imported' => 'Data imported',
    'audit_action.email.verified' => 'Email address verified',
    'audit_action.household.updated' => 'Household renamed',
    'audit_action.instance.settings_changed' => 'Instance settings changed',
    'audit_action.login.blocked' => 'Sign-in blocked by throttle',
    'audit_action.login.failed' => 'Failed sign-in',
    'audit_action.login.succeeded' => 'Signed in',
    'audit_action.logout' => 'Signed out',
    'audit_action.membership.role_changed' => 'Household role changed',
    'audit_action.notification_channel.created' => 'Notification channel added',
    'audit_action.notification_channel.deleted' => 'Notification channel deleted',
    'audit_action.notification_channel.updated' => 'Notification channel changed',
    'audit_action.passkey.registered' => 'Passkey added',
    'audit_action.passkey.renamed' => 'Passkey renamed',
    'audit_action.passkey.revoked' => 'Passkey revoked',
    'audit_action.password.changed' => 'Password changed',
    'audit_action.password.reset_requested' => 'Password reset requested',
    'audit_action.secrets.rotated' => 'Stored secrets re-encrypted',
    'audit_action.session.revoked' => 'Session revoked',
    'audit_action.session.revoked_all' => 'All other sessions revoked',
    'audit_action.totp.disabled' => 'Authenticator app disabled',
    'audit_action.totp.enabled' => 'Authenticator app enabled',
    'audit_action.totp.recovery_code_used' => 'Recovery code used',
    'audit_action.totp.recovery_codes_regenerated' => 'Recovery codes regenerated',
    'audit_action.outbound.refused' => 'Outbound request refused',
    'audit_action.trusted_host.added' => 'Trusted host added',
    'audit_action.trusted_host.removed' => 'Trusted host removed',
    'audit_action.two_factor.failed' => 'Second factor rejected',
    'audit_action.two_factor.succeeded' => 'Second factor accepted',

    // audit_entries
    'audit_entries.no_events_recorded' => 'No events recorded.',
    'audit_entries.pager' => 'Page {page} of {pages} · {total, plural, one {# entry} other {# entries}}',

    // auth
    'auth.link_expired' => 'Link expired',

    // auth_forgot_password
    'auth_forgot_password.intro' =>
        'Enter the email you sign in with. If it belongs to an account, we’ll send a link that works for '
        . '{minutes, plural, one {# minute} other {# minutes}}.',
    'auth_forgot_password.send_reset_link' => 'Send reset link',
    'auth_forgot_password.title' => 'Reset your password',

    // auth_forgot_password_sent
    'auth_forgot_password_sent.intro' =>
        'If {email} has an account, a reset link is on its way. It expires in '
        . '{minutes, plural, one {# minute} other {# minutes}} and works once.',
    'auth_forgot_password_sent.nothing_arrived' =>
        'Nothing arrived? Check spam, or ask your household Owner to send a reset from Members & roles.',
    'auth_forgot_password_sent.send_again' => 'Send again',
    'auth_forgot_password_sent.title' => 'Check your inbox',

    // auth_login
    'auth_login.forgot_password' => 'Forgot password?',
    'auth_login.keep_signed_in' => 'Keep me signed in on this device',
    'auth_login.no_account' => 'New here?',
    'auth_login.passkey' => 'Sign in with a passkey',
    'auth_login.title' => 'Sign in',
    'auth_login.welcome_back' => 'Welcome back.',

    // auth_register
    'auth_register.already_have_an_account' => 'Already have an account?',
    'auth_register.create_account' => 'Create account',
    'auth_register.intro' => 'Your own account, with a household of your own to start in.',
    'auth_register.title' => 'Create an account',

    // auth_register_sent
    'auth_register_sent.intro' =>
        'We’ve sent a link to {email}. Follow it to finish setting up your account; it works for '
        . '{days, plural, one {# day} other {# days}}.',
    'auth_register_sent.nothing_arrived' => 'Nothing arrived? Check spam, or send the link again.',
    'auth_register_sent.resent' => 'If that address is waiting to be confirmed, a new link is on its way.',
    'auth_register_sent.send_again' => 'Send again',
    'auth_register_sent.title' => 'Confirm your email',

    // auth_reset_expired
    'auth_reset_expired.intro' =>
        'Reset links work for {minutes, plural, one {# minute} other {# minutes}} and only once. Ask for a '
        . 'new one and use it straight away.',
    'auth_reset_expired.send_a_new_link' => 'Send a new link',
    'auth_reset_expired.title' => 'That reset link has expired',

    // auth_reset_password
    'auth_reset_done.intro' => 'Your password has been changed. Sign in with the new one.',
    'auth_reset_done.title' => 'Password saved',
    'auth_reset_password.confirm_new_password' => 'Confirm new password',
    'auth_reset_password.intro' => 'Choose a password you have not used here before.',
    'auth_reset_password.new_password' => 'New password',
    'auth_reset_password.save_password' => 'Save password',
    'auth_reset_password.title' => 'Choose a new password',

    // auth_two_factor
    'auth_two_factor.code_label' => 'Code from your authenticator app',
    'auth_two_factor.enter_code' => 'Enter the 6-digit code from your authenticator app for {email}.',
    'auth_two_factor.no_factor' => 'This account has no second factor available. Ask an administrator for help.',
    'auth_two_factor.passkey' => 'Use a passkey instead',
    'auth_two_factor.recovery_code' => 'Recovery code',
    'auth_two_factor.recovery_hint' => 'One of the codes you saved when you turned on two-step verification.',
    'auth_two_factor.title' => 'Two-step verification',
    'auth_two_factor.use_a_recovery_code' => 'Use a recovery code',
    'auth_two_factor.use_passkey_intro' => 'Use the passkey registered to {email} to finish signing in.',
    'auth_two_factor.use_recovery_code' => 'Use recovery code',
    'auth_two_factor.verify' => 'Verify and continue',

    // auth_verify_failed
    'auth_verify_failed.intro' =>
        'Confirmation links work for {days, plural, one {# day} other {# days}} and only once. Enter your '
        . 'email and we’ll send a new one.',
    'auth_verify_failed.send_new_link' => 'Send a new link',
    'auth_verify_failed.title' => 'That link is no longer valid',
    'auth_verify_done.intro' => 'Your email address is confirmed. Sign in to start.',
    'auth_verify_done.title' => 'Email confirmed',
    'auth_verify_used.intro' => 'This link has been used already, so your address is confirmed. Sign in to carry on.',
    'auth_verify_used.title' => 'Already confirmed',

    // backup
    'backup.archive_label' => 'Backup archive (format {version})',
    'backup.download_backup' => 'Download backup',
    'backup.export_exclusions' =>
        'Not included: user accounts, passwords, API tokens, the audit log and instance-wide settings. '
        . 'Those belong to the server rather than to this household.',
    'backup.export_intro' =>
        'Downloads a ZIP containing everything this household has: its subscriptions, categories, tags, '
        . 'budgets, logos and attached invoices, as JSON plus the original files. Nothing in it needs a '
        . 'database to read.',
    'backup.export_scope' =>
        'It contains what you can see. If this instance keeps members\' data separate, your export has '
        . 'your own subscriptions and not other members\'.',
    'backup.private_left_out' =>
        '{count, plural, one {# subscription} other {# subscriptions}} that another member keeps to '
        . 'themselves will not be in the backup.',
    'backup.restore' => 'Restore',
    'backup.restore_is_additive' =>
        'Reads a backup archive back into this household. It adds — nothing is deleted or overwritten, '
        . 'so restoring the same file twice leaves two copies of everything.',
    'backup.restore_matching' =>
        'Members are matched by email address. A subscription whose owner is no longer in this household '
        . 'comes back owned by you, and every file in the archive is re-checked before it is stored.',

    // budgets
    'budgets.alerts_off' => 'Alerts off',
    'budgets.alerts_on' => 'Alert when projected over · {channels}',
    'budgets.all_categories' => 'All categories',
    'budgets.bar_label' => '{charged} charged so far and {projected} projected, of a {limit} limit',
    'budgets.edit_named' => 'Edit {name}',
    'budgets.empty' => 'No budgets yet.',
    'budgets.history_alt' => 'Household spend each month for the last six months, in {currency}',
    'budgets.history_caption' => 'Spend each month against the {limit} limit · over in {count} of {months}',
    'budgets.history_caption_uncounted' => 'Spend each month against the {limit} limit',
    'budgets.history_title' => 'Household total, last six months',
    'budgets.household' => 'Household',
    'budgets.intro' =>
        'Each budget compares a limit with what your subscriptions will cost in the period, including '
        . 'trials about to convert. You are alerted when one is projected over.',
    'budgets.new_budget' => 'New budget',
    'budgets.note_left' => '{amount} left',
    'budgets.note_over' => 'Over by {amount}',
    'budgets.note_over_if_trials' => 'Projected {projected} if trials convert — over by {amount}',
    'budgets.note_warning' => '{percent}% used — past the {threshold}% warning',
    'budgets.of_limit_projected' => 'of {limit} projected',
    'budgets.percent' => '{percent}%',
    'budgets.set_one_up' => 'Set one up.',
    'budgets.state_bad' => 'Over',
    'budgets.state_ok' => 'On track',
    'budgets.state_warn' => 'Warning',
    'budgets.tile_household_limit' => 'Household limit',
    'budgets.tile_over' => 'Projected over',
    'budgets.tiles' => 'Budgets by state',
    'budgets.unavailable' =>
        'Not available to you: it measures spending you cannot see while members\' subscriptions are '
        . 'kept separate.',
    'budgets.unconvertible' => 'Projection unavailable — no rate for {currencies}',
    'budgets.warn_tick' => 'Warning at {percent}%',

    // budgets_form
    'budgets_form.edit_title' => 'Edit budget',
    'budgets_form.limit_in' => 'Limit ({currency})',
    'budgets_form.name_placeholder' => 'e.g. Streaming',
    'budgets_form.save_budget' => 'Save budget',
    'budgets_form.subject_hint' =>
        'A member\'s budget counts only their share of what is spent. A household budget counts all of it.',
    'budgets_form.subject_locked_hint' =>
        'This budget measures spending you cannot choose here, and saving leaves that as it is.',
    'budgets_form.threshold_hint' =>
        'Shown as a warning once projected spend reaches this share of the limit. Alerts are sent only '
        . 'when a budget is projected over.',
    'budgets_form.warn_at' => 'Warn me at',
    'budgets_form.whose_spending' => 'Whose spending',

    // calendar
    'calendar.caption' => 'Charges, trial ends and cancel-by deadlines in {month}',
    'calendar.charges' => 'Charges',
    'calendar.status' => '{month}: {count, plural, one {# charge} other {# charges}}',
    'calendar.charges_count' => '{count, plural, one {# charge} other {# charges}}',
    'calendar.feed_copy' => 'Copy',
    'calendar.feed_create' => 'Create a link',
    'calendar.feed_created' => 'Your link was created on {date}.',
    'calendar.feed_hidden' =>
        'The link itself is shown only when it is created. To add it to another calendar, create a new one.',
    'calendar.feed_intro' =>
        'Subscribe in Google Calendar, Apple Calendar or Outlook to see renewals, trial conversions '
        . 'and cancel-by deadlines.',
    'calendar.feed_last_used' => 'A calendar last fetched it on {date}.',
    'calendar.feed_never_used' => 'No calendar has fetched it yet.',
    'calendar.feed_new_link' => 'Create a new link',
    'calendar.feed_none' => 'You have no feed link yet.',
    'calendar.feed_replace_confirm' => 'Stop the old link and create a new one',
    'calendar.feed_replace_warning' =>
        'The current link stops working at once, and every calendar subscribed to it stops updating '
        . 'until it is given the new one.',
    'calendar.feed_shown_once' => 'Copy it now: for your security the link is shown only this once.',
    'calendar.feed_title' => 'Calendar feed',
    'calendar.feed_url' => 'Calendar feed address',
    'calendar.heaviest_day' => 'Heaviest day',
    'calendar.item_cancel_by' => 'Cancel by — notice period {notice}',
    'calendar.item_trial' => 'Trial ends — converts to paid',
    'calendar.just_mine' => 'Just mine',
    'calendar.kind_cancel_by' => 'Cancel by',
    'calendar.kind_charge' => 'Charge',
    'calendar.kind_trial' => 'Trial ends',
    'calendar.legend' => 'Key',
    'calendar.month_navigation' => 'Month navigation',
    'calendar.month_total' => 'Month total',
    'calendar.more' => '+{count} more',
    'calendar.next_month' => 'Next month',
    'calendar.nothing_due' => 'Nothing due this month.',
    'calendar.nothing_on_day' => 'Nothing due on this day.',
    'calendar.previous_month' => 'Previous month',
    'calendar.selected' => 'selected',
    'calendar.summary' => 'Month summary',

    // cancellations
    'cancellations.days_left' => '{days, plural, one {# day left} other {# days left}}',
    'cancellations.deadline_passed' => 'Deadline passed',
    'cancellations.contract_ended' => 'Contract ended',
    'cancellations.contract_ended_days' =>
        '{days, plural, one {# day ago} other {# days ago}} — a good time to compare prices or renegotiate.',
    'cancellations.contract_ends' => 'Contract ends',
    'cancellations.empty' =>
        'Nothing with a notice period or a contract end date. Add one to a subscription and it will appear '
        . 'here.',
    'cancellations.intro' =>
        'The date that matters is not the renewal — it is the last day you can still give notice and '
        . 'avoid the next charge. Only subscriptions with a notice period appear here, with the day any '
        . 'contract ends; everything else can be cancelled right up to its renewal date, which the '
        . 'subscriptions list already shows.',
    'cancellations.note' =>
        'Deadlines within {days} days are highlighted. Anything already passed is kept on the list '
        . 'rather than hidden — you have been committed to another period, and that is worth knowing.',
    'cancellations.notice' => 'Notice',
    'cancellations.passed_days' =>
        '{days, plural, one {# day ago} other {# days ago}} — this renewal is already committed.',

    // categories
    'categories.colour' => 'Colour',
    'categories.colour_of' => 'Colour of {name}',
    'categories.colour_automatic' => 'Automatic colour',
    'categories.colour_automatic_of' => 'Automatic colour for {name}',
    'categories.new_category' => 'New category',
    'categories.no_categories_yet' => 'No categories yet.',
    'categories.no_tags_yet' => 'No tags yet.',

    // confirm — the questions a destructive control asks before it acts. They
    // are read out by the browser's own dialog, which is why they are short
    // sentences rather than labels, and they are here rather than written into
    // an onsubmit attribute so that one check covers every string the
    // application can show.
    'confirm.cancel_subscription' =>
        'Cancel {name}? It stops counting from today. You can undo it, and it comes back paused.',
    'confirm.delete_subscription' => 'Delete {name}?',
    'confirm.delete_category' => 'Delete {name}?',
    'confirm.delete_payment_method' =>
        'Delete {name}? Subscriptions paid with it are kept; they just stop saying how they are paid.',
    'confirm.delete_budget' => 'Delete this budget?',
    'confirm.delete_attachment' => 'Delete this file?',
    'confirm.reset_usage' => 'Reset the usage count to zero?',
    'confirm.restore_backup' => 'Restore this archive into the current household?',
    'confirm.reissue_token' =>
        'Reissue this token? The current one stops working straight away, and anything using it will need '
        . 'the new one.',
    'confirm.revoke_token' => 'Revoke this token? Anything using it stops working straight away.',

    // dashboard
    'dashboard.active' => 'Active',
    'dashboard.all_subscriptions' => 'All subscriptions',
    'dashboard.already_charged' => 'Already charged',
    'dashboard.approx' => '≈ {amount}',
    'dashboard.at_todays_prices' => 'at today’s prices',
    'dashboard.budget_household' => 'Household',
    'dashboard.budget_left' => '{amount} left this month',
    'dashboard.budget_meter' => '{charged}% charged so far, {projected}% projected by the end of the month',
    'dashboard.budget_over_if_trials' => 'Projected over if the running trials convert',
    'dashboard.budget_pace' => 'Spent this year vs budget pace',
    'dashboard.budget_pace_note' => 'Cumulative, January to now, {year}',
    'dashboard.budget_past_warning' => 'Past the {threshold}% warning',
    'dashboard.budget_projected_over' => 'Projected {amount} over',
    'dashboard.budget_yours' => 'You',
    'dashboard.budgets_this_month' => 'Budgets · {month}',
    'dashboard.busiest_month' => 'Busiest',
    'dashboard.cancel_trial' => 'Cancel trial',
    'dashboard.cancel_trial_named' => 'Cancel the {name} trial',
    'dashboard.charged_of_due' => 'already charged of {total} due this month',
    'dashboard.charges_count' => '{count, plural, =0 {No charges} one {# charge} other {# charges}}',
    'dashboard.coming_up' => 'Coming up',
    'dashboard.coming_up_more' =>
        'And {count, plural, one {# more charge} other {# more charges}} in the next {days} days.',
    'dashboard.coming_up_note' => 'Charges and trial conversions in the next {days} days',
    'dashboard.converts_to' => 'Converts to',
    'dashboard.converts_on' => 'converts {date}',
    'dashboard.due_in_total' => 'due in total',
    'dashboard.due_next_days' => 'Due next {days} days',
    'dashboard.due_this_month' => 'Due this month',
    'dashboard.excluded_note' =>
        '{count, plural, one {# subscription has} other {# subscriptions have}} no start date and '
        . '{count, plural, one {is} other {are}} left out.',
    'dashboard.filter_rows' => 'Which subscriptions to list',
    'dashboard.forecast' => 'Forecast',
    'dashboard.free_trials' => 'Free trials',
    'dashboard.greeting' => 'Welcome back, {name}',
    'dashboard.layout_customise' => 'Customise',
    'dashboard.layout_done' => 'Done',
    'dashboard.layout_drag' => 'Drag to move {card}',
    'dashboard.layout_failed' => 'That change could not be saved. The card is back where it was.',
    'dashboard.layout_hidden' => 'Hidden',
    'dashboard.layout_hide' => 'Hide {card}',
    'dashboard.layout_hint' =>
        'Drag a card by its handle, or use the arrows, to move it. Hidden cards stay here so you can show them again.',
    'dashboard.layout_move_down' => 'Move {card} down',
    'dashboard.layout_move_up' => 'Move {card} up',
    'dashboard.layout_moved' => '{card} moved to position {position}',
    'dashboard.layout_reset' => 'Reset layout',
    'dashboard.layout_show' => 'Show {card}',
    'dashboard.metrics' => 'Spending at a glance',
    'dashboard.month_bar_label' => '{charged} already charged, {due} still due',
    'dashboard.month_so_far' => '{month} so far',
    'dashboard.monthly_equivalent' => 'Monthly equivalent',
    'dashboard.monthly_from_trials' =>
        '{count, plural, =0 {No trials running} one {a month from # trial} other {a month from # trials}}',
    'dashboard.monthly_spend' => 'Monthly spend',
    'dashboard.next_12_months' => 'Next 12 months',
    'dashboard.next_days' => 'Next {days} days',
    'dashboard.no_budget' => 'No budget set for you yet, so there is nothing to measure this against.',
    'dashboard.no_household' => 'No household',
    'dashboard.no_household_note' =>
        'You are not a member of a household yet, so there is nothing to show. Instance administration '
        . 'does not by itself grant access to anybody\'s subscriptions.',
    'dashboard.no_trials' => 'No trials running.',
    'dashboard.nothing_coming_up' => 'Nothing due in the next {days} days.',
    'dashboard.nothing_due' => 'Nothing due.',
    'dashboard.nothing_renewing' => 'Nothing renewing in the near window.',
    'dashboard.of_household_spend' => '{percent}% of household spend',
    'dashboard.offer_ends' => 'Intro offer ending',
    'dashboard.offer_ends_title' => '{name}’s intro price ends on {date}: {from} becomes {to}',
    'dashboard.other_categories' => '{count, plural, one {# other category} other {# other categories}}',
    'dashboard.pace_budget_to_date' => 'budget to date',
    'dashboard.pace_of_monthly' => 'An even pace of twelve times the {budget} monthly household budget.',
    'dashboard.pace_of_yearly' => 'An even pace of the {budget} yearly household budget.',
    'dashboard.pace_over' => '{amount} over pace',
    'dashboard.pace_spent' => 'spent',
    'dashboard.pace_unconvertible' =>
        'No exchange rate for {currencies}, so this year’s spend cannot be added up in one currency.',
    'dashboard.pace_under' => '{amount} under pace',
    'dashboard.one_off_in' => 'One-off & lifetime · {currency}',
    'dashboard.one_off_note' => '{count, plural, one {# entry} other {# entries}}, not included in monthly totals',
    'dashboard.per_month_unit' => '/ month',
    'dashboard.per_year_unit' => '/ year',
    'dashboard.percent_of_budget' => '{percent}% of {budget} budget',
    'dashboard.price_change' => 'Price change',
    'dashboard.price_rise_difference' =>
        '{monthly} a month, {yearly} a year. Already included in the forecast.',
    'dashboard.price_rises' => '{name} rises from {from} to {to} on {date}',
    'dashboard.recent' => 'Subscriptions',
    'dashboard.reconstructed_note' =>
        'From the payment ledger; months before a payment was recorded are rebuilt from start dates and price history.',
    'dashboard.recurring_empty' => 'No active recurring subscriptions yet.',
    'dashboard.renewing_soon' => 'Renewing soon',
    'dashboard.renewing_soon_note' => 'in the next {days} days',
    'dashboard.see_all' => 'See all',
    'dashboard.see_price_history' => 'See price history',
    'dashboard.series_actual' => 'Actual',
    'dashboard.series_budget' => 'Budget {budget}',
    'dashboard.see_forecast' => 'See the forecast',
    'dashboard.series_forecast' => 'Forecast',
    'dashboard.share_of_spend' => '{name}: {percent}% of monthly spend',
    // The year ahead is no longer a dashboard card, but these three keep their
    // `dashboard.` prefix: they describe the picture `partials/spend_chart.twig`
    // draws, which the Analytics trajectory renders and which asks for them by
    // name. Renaming them would be renaming the partial's vocabulary to record
    // where it was first used.
    'dashboard.spend_bars_alt' =>
        'Spend per month in {currency}: six months reconstructed, this month charged so far and still due, '
        . 'and six months forecast.',
    'dashboard.spend_bars_unconvertible' =>
        'The monthly spend chart is not drawn: no exchange rate is available for {currencies}, and a month '
        . 'missing one of its currencies would look like a cheap month rather than an unknown one.',
    'dashboard.spend_chart_note' => 'What was charged each month, and the forecast for the next six',
    'dashboard.spend_trend' => 'Spend over time',
    'dashboard.spend_trend_by_category' => 'By category',
    'dashboard.spend_trend_by_member' => 'By member',
    'dashboard.spend_trend_status' => 'Spend trend, drawn {choice}',
    'dashboard.spend_trend_choice' => 'Draw a line for each',
    'dashboard.spend_trend_empty' => 'Nothing was charged in the last {count} months.',
    'dashboard.spend_trend_latest' => 'The figure beside each name is last month’s.',
    'dashboard.spend_trend_note' => 'The last {count} full months, in {currency}',
    'dashboard.spend_trend_unconvertible' =>
        'No exchange rate for {currencies}, so these months cannot be added up in one currency.',
    'dashboard.spend_trend_uncategorised' => 'Uncategorised',
    // Said rather than implied: past months come from the payment ledger, and
    // the months before a subscription's first recorded payment are rebuilt
    // from start dates, billing cycles and price history. A reader comparing
    // them with a bank statement should know that before they do it.
    'dashboard.stands_on' => 'Here’s where {household} stands on {date}.',
    'dashboard.still_due' => 'Still due',
    'dashboard.this_month' => 'This month',
    'dashboard.today' => 'Today',
    'dashboard.trial_converts' => 'Trial converts',
    'dashboard.trial_ends' => 'Ends {date} · {days, plural, one {# day} other {# days}} left',
    'dashboard.trial_ends_today' => 'Ends today',
    'dashboard.trial_started_by' => 'started by {name}',
    'dashboard.trials_and_paused' =>
        '{trials, plural, one {# trial} other {# trials}} · {paused} paused',
    'dashboard.trials_converting' => 'Trials converting',
    'dashboard.trials_total_note' => 'about to start being charged',
    'dashboard.view_active' => 'Active',
    'dashboard.view_all' => 'All',
    'dashboard.view_choice' => 'Which dashboard',
    'dashboard.view_expiring' => 'Renewing soon',
    'dashboard.vs_last_year' => '{percent, number, ::sign-always}% against the same period last year',
    'dashboard.where_it_goes' => 'Where it goes',
    'dashboard.where_it_goes_note' => 'Monthly equivalent by category, in {currency}',
    'dashboard.who_pays' => 'Who pays what',
    'dashboard.who_pays_note' => 'Monthly share after splits',
    'dashboard.year_to_date' => 'Year to date',
    'dashboard.yearly_run_rate' => 'Yearly run-rate',
    'dashboard.unconvertible' =>
        'Totals are shown per currency. They cannot be combined because no exchange rate is available '
        . 'for {currencies} — a total leaving that out would be a wrong number rather than an approximate '
        . 'one.',
    'dashboard.your_share' => 'Your share',

    // error
    'error.back_to_the_dashboard' => 'Back to the dashboard',

    // field
    'field.actions' => 'Actions',
    'field.added' => 'Added',
    'field.address' => 'Address',
    'field.alert' => 'Alert',
    'field.amount' => 'Amount',
    'field.category' => 'Category',
    'field.offer_ends_on' => 'Offer ends',
    'field.offer_then_price' => 'Then costs',
    'field.payment_method' => 'Payment method',
    'field.change' => 'Change',
    'field.date' => 'Date',
    'field.channel' => 'Channel',
    'field.confirm_password' => 'Confirm password',
    'field.currency' => 'Currency',
    'field.cycle' => 'Cycle',
    'field.detail' => 'Detail',
    'field.email' => 'Email address',
    'field.event' => 'Event',
    'field.file' => 'File',
    'field.member' => 'Member',
    'field.month' => 'Month',
    'field.name' => 'Name',
    'field.next_charge' => 'Next charge',
    'field.next_payment' => 'Next payment',
    'field.note' => 'Note',
    'field.notes' => 'Notes',
    'field.password' => 'Password',
    'field.per_month' => 'Per month',
    'field.per_year' => 'Per year',
    'field.period' => 'Period',
    'field.plan' => 'Plan',
    'field.price' => 'Price',
    'field.rating' => 'Rating',
    'field.result' => 'Result',
    'field.role' => 'Role',
    'field.share' => 'Share',
    'field.size' => 'Size',
    'field.status' => 'Status',
    'field.subscription' => 'Subscription',
    'field.subscriptions' => 'Subscriptions',
    'field.tag' => 'Tag',
    'field.tags' => 'Tags',
    'field.total' => 'Total',
    'field.type' => 'Type',
    'field.uses' => 'Uses',
    'field.website' => 'Website',
    'field.when' => 'When',
    'field.who' => 'Who',
    'field.your_name' => 'Your name',

    // forecast
    'forecast.cancel_charges' => '{count, plural, one {# charge} other {# charges}}',
    'forecast.cancel_empty' => 'Nothing recurring is due in the next 12 months.',
    'forecast.cancel_heading' => 'If you cancelled',
    'forecast.add_to_scenario' => 'Add to scenario',
    'forecast.add_to_scenario_name' => 'Add cancelling {name} to a scenario',
    'forecast.cancel_note' => 'What cancelling today would save over the next 12 months',
    'forecast.change_from' => 'From {date}',
    'forecast.changes_heading' => 'Price changes ahead',
    'forecast.chart_alt' => 'Forecast spend for each of the next 12 months, in {currency}',
    'forecast.chart_heading' => 'The next 12 months',
    'forecast.chart_note' => 'Renewals in the month they fall, trials from the day they convert',
    'forecast.kpi_average' => 'Average month',
    'forecast.kpi_average_note' => 'The next 12 months, evenly spread',
    'forecast.kpi_busiest' => 'Busiest month',
    'forecast.kpi_long' => 'Yearly & longer renewals',
    'forecast.kpi_long_none' => 'None in the next 12 months',
    'forecast.kpi_long_note' => '{count, plural, one {# renewal} other {# renewals}} in the next 12 months',
    'forecast.mine_note' => 'Showing only your share of each charge.',
    'forecast.month_in_calendar' => '{month} in the calendar',
    'forecast.series_long' => 'Yearly & longer',
    'forecast.series_regular' => 'Monthly & more often',
    'forecast.trial_converts_on' => 'Converts {date}',
    'forecast.trial_kept' => '{amount} in the next 12 months if kept',
    'forecast.trials_empty' => 'No trials convert in the next 12 months.',
    'forecast.trials_heading' => 'Trials converting',
    'forecast.show_only_my_share' => 'Show only my share',
    'forecast.show_whole_household' => 'Show the whole household',

    // form
    'form.has_errors' => 'Check the highlighted fields and try again.',

    // hint

    // household
    'household.figures_withheld' => 'Not shown',
    'household.one_off_count' =>
        '{count, plural, one {# of these is one-off} other {# of these are one-off}}',
    'household.subscriptions_count' =>
        '{count, plural, =0 {No subscriptions} one {# subscription} other {# subscriptions}}',

    // import_map
    'import_map.column_in_your_file' => 'Column in your file',
    'import_map.do_not_import' => 'Do not import',
    'import_map.example' => 'Example',
    'import_map.field' => 'Field',
    'import_map.map_the_columns' => 'Map the columns',
    'import_map.required_note' => '* Name and price are required. A row missing either is skipped.',
    'import_map.summary' =>
        '{rows, plural, one {# row} other {# rows}}. Suggested using “{preset}”. Change anything that '
        . 'looks wrong; “Do not import” leaves a field empty.',
    'import_map.title' => 'Map columns',

    // import_preview
    'import_preview.back_to_mapping' => 'Back to mapping',
    'import_preview.commit' => 'Import {count, plural, one {# subscription} other {# subscriptions}}',
    'import_preview.intro' =>
        'Rows with a problem are marked below. Importing goes ahead without them — or go back and change '
        . 'the mapping.',
    'import_preview.problem' => 'Problem',
    'import_preview.ready' => 'Ready',
    'import_preview.skipped' => '{invalid, plural, one {# row} other {# rows}} will be skipped.',
    'import_preview.summary' => '{valid, plural, one {# row} other {# rows}} will be imported.',
    'import_preview.title' => 'Preview import',

    // import_start
    'import_start.file_hint' => 'CSV (comma, semicolon or tab separated) or JSON, up to {rows} rows.',
    'import_start.import_subscriptions' => 'Import subscriptions',
    'import_start.intro' =>
        'Bring in a CSV or JSON file from a spreadsheet or another tracker. You choose which column '
        . 'feeds which field, and you see exactly what will be created before anything is written.',
    'import_start.it_came_from' => 'It came from',
    'import_start.source_hint' => 'Only a starting point for the column mapping — you confirm it on the next screen.',

    // insight
    'insight.action' => 'Review subscription',
    'insight.more' => '{count, plural, one {# more insight} other {# more insights}}',
    'insight.note' =>
        'Each of these is a rule over your own subscriptions, and every figure is one '
        . 'subscription\'s own price. Nothing here is estimated, and nothing is converted.',
    'insight.overlap.detail' =>
        'Dropping {name} would remove {amount} a year. Also in this category: {others}.',
    'insight.estimate_off.detail' =>
        '{name}’s last {count} bills averaged {average} against an estimate of {estimate}. Use the '
        . 'average as the estimate from its Payments card.',
    'insight.estimate_off.headline_high' => 'An estimate looks high',
    'insight.estimate_off.headline_low' => 'An estimate looks low',
    'insight.out_of_contract.detail' =>
        '{name} has been out of contract since {date} — a good time to compare prices or renegotiate.',
    'insight.out_of_contract.headline' => 'Out of contract',
    'insight.overlap.headline' =>
        '{count, plural, one {# subscription} other {# subscriptions}} in {category}',
    'insight.price_risen.detail' =>
        '{name} went up by {difference} on {date} — {amount} a year more than before.',
    'insight.price_risen.headline' => 'A price went up',
    'insight.price_rising.detail' =>
        '{name} goes up by {difference} on {date} — {amount} a year more than now.',
    'insight.price_rising.headline' => 'A price is going up',
    'insight.promo_ending.detail' => '{name}’s intro price ends on {date} — then {price}{per} ({difference}).',
    'insight.promo_ending.headline' => 'An intro offer is ending',
    'insight.promo_no_end.detail' => '{name} is on an intro price with no end date — add one so you’re warned before it rises.',
    'insight.promo_no_end.headline' => 'An intro price has no end date',
    'insight.rarely_used.detail' =>
        '{name} costs {amount} a year and has been used {count, plural, one {# time} other {# times}}.',
    'insight.rarely_used.headline' => 'Paid for, barely used',
    'insight.rarely_used.per_use' => 'That works out at {amount} a use.',
    'insight.title' => 'Spend insights',
    'insight.trial_converting.detail' =>
        '{name} converts on {date}, {days, plural, =0 {today} one {# day away} other {# days away}}, '
        . 'and starts costing {amount} a year.',
    'insight.trial_converting.headline' => 'A free trial is about to convert',

    // js
    'js.dashboard.part_month' => 'part month',
    'js.dashboard.spend' => 'Spend',
    'js.dashboard.spend_committed' => 'Excluding trial conversions',
    'js.dashboard.spend_with_trials' => 'Including trial conversions',
    'js.dashboard.trial_gap' => 'Trials add {amount}',
    'js.copied' => 'Copied.',
    'js.copy_failed' => 'That could not be copied. Select the address and copy it yourself.',
    'js.dialog_loading' => 'Loading…',
    'js.percent' => '{percent}%',
    'js.quick_add_failed' => 'That form could not be loaded. Open the full page instead.',
    'js.passkey_generic_error' => 'That did not work. Try again.',
    'js.passkey_not_used' => 'No passkey was used.',
    'js.passkey_register_cancelled' => 'Registration was cancelled.',
    'js.passkey_register_failed' => 'That key could not be registered: {reason}',
    'js.passkey_register_unsupported' => 'This browser cannot register passkeys on this connection.',
    'js.passkey_unsupported' => 'This browser cannot use passkeys on this connection.',
    'js.passkey_use_failed' => 'That passkey could not be used: {reason}',

    // nav
    'nav.analytics' => 'Analytics',
    'nav.audit' => 'Audit',
    'nav.budgets' => 'Budgets',
    'nav.calendar' => 'Calendar',
    'nav.cancellations' => 'Cancel by',
    'nav.categories' => 'Categories',
    'nav.payment_methods' => 'Payment methods',
    'nav.dashboard' => 'Dashboard',
    'nav.forecast' => 'Forecast',
    'nav.household' => 'Household',
    'nav.household_tools' => 'Household tools',
    'nav.import' => 'Import',
    'nav.members' => 'Members & roles',
    'nav.more' => 'More',
    'nav.notifications' => 'Notifications',
    'nav.primary' => 'Primary',
    'nav.profile' => 'Profile',
    'nav.search' => 'Search',
    'nav.settings' => 'Settings',
    'nav.sign_out' => 'Sign out',
    'nav.skip_to_content' => 'Skip to content',
    'nav.subscriptions' => 'Subscriptions',
    'nav.tab_add' => 'Add',
    'nav.tab_home' => 'Home',
    'nav.tab_subscriptions' => 'Subs',
    'nav.tools' => 'Tools',

    // shell: the rail's and the top bar's own words
    'shell.add_new' => 'Add new',
    'shell.badge_active' => 'active',
    'shell.bell' => 'What\'s coming up',
    'shell.bell_due_soon' => 'A trial or a cancel-by deadline is close',
    'shell.household_meta' => '{count, plural, one {# member} other {# members}} · you\'re {role}',
    'shell.rates_on' => '{currency} · rates {date}',
    'shell.rates_stale' => 'Out of date',
    'shell.rates_unavailable' => '{currency} · rates unavailable',
    'shell.search' => 'Search subscriptions…',
    'shell.tagline' => 'Household spend',
    'shell.theme_to_dark' => 'Switch to the dark theme',
    'shell.theme_to_light' => 'Switch to the light theme',
    'shell.your_profile' => 'Your profile',

    // subtitle: the short line under each page's title in the top bar
    'subtitle.audit' => 'Who changed what, and when',
    'subtitle.budget_form' => 'A limit for a period, a category or one person',
    'subtitle.budgets' => 'Limits against projected spend',
    'subtitle.calendar' => 'Renewals, trials & deadlines',
    'subtitle.cancellations' => 'Deadlines to cancel before the next charge',
    'subtitle.dashboard' => 'Your household at a glance',
    'subtitle.forecast' => 'What the coming year will cost',
    'subtitle.scenario' => 'What cancelling or changing would save, before you change anything',
    'subtitle.import' => 'Bring subscriptions in from a file',
    'subtitle.import_map' => 'Match your columns to the fields',
    'subtitle.import_preview' => 'Check it before anything is saved',
    'subtitle.member_remove' => 'What happens to what they own',
    'subtitle.money' => 'Price history, splits and usage',
    'subtitle.notifications' => 'How and when you are reminded',
    'subtitle.profile' => 'Your account, sign-in and appearance',
    'subtitle.settings' => 'Choices for the household and the instance',
    'subtitle.stats' => 'Spending, forecast and price history',
    'subtitle.subscription_form' => 'Price, renewal date, who pays and who can see it',
    'subtitle.subscriptions' => 'Everything the household pays for',
    'subtitle.totp_setup' => 'Pair an authenticator app',

    // notifications
    'notifications.add_a_channel' => 'Add a channel',
    'notifications.add_channel_of_type' => 'Add {type}',
    'notifications.channels' => 'Channels',
    'notifications.channels_empty' => 'No channels yet. Add one below and send yourself a test message.',
    'notifications.delivery' => 'Delivery',
    'notifications.digest_day_hint' =>
        'For a weekly summary, 1 is Monday. For a monthly one it is the day of the month, up to 28 — a '
        . 'summary set for the 30th would skip February.',
    'notifications.digest_hint' =>
        'A summary collects everything coming up into one message instead of sending them separately. '
        . 'Lead times do not apply to a summary — each thing is mentioned once, shortly before it happens.',
    'notifications.intro' =>
        'Reminders are sent by the scheduler once a day. Each one is recorded when it goes out, so a run '
        . 'that happens twice — or a scheduler that catches up after being offline — never sends you the '
        . 'same thing again.',
    'notifications.last_delivered' => 'Last delivered {when}.',
    'notifications.last_error' => 'Last attempt failed: {reason}',
    'notifications.price_change_hint' =>
        'When a price is edited or a future one is scheduled, on any subscription you can see. Routed '
        . 'like the other alerts below.',
    'notifications.price_change_toggle' => 'Tell me when a price changes',
    'notifications.contract_hint' =>
        'At your lead times before a contract end date you have recorded. Routed like the other alerts '
        . 'below.',
    'notifications.contract_toggle' => 'Tell me before a contract ends',
    'notifications.recently_sent' => 'Recently sent',
    'notifications.routing_hint' =>
        'Which channel each alert goes to. Channels that are off are greyed out, and keep their choices '
        . 'for when they are turned back on. Clearing every box sends everything everywhere.',
    'notifications.save_channel' => 'Save channel',
    'notifications.save_preferences' => 'Save preferences',
    'notifications.secret_placeholder' => 'Leave blank to keep the stored value',
    'notifications.summary_day' => 'Summary day',
    'notifications.title' => 'Notifications',

    // period
    'period.per_daily' => 'Per day',
    'period.per_monthly' => 'Per month',
    'period.per_weekly' => 'Per week',
    'period.per_yearly' => 'Per year',

    // quick_add
    'quick_add.loading' => 'Loading…',

    // shortcuts
    'shortcuts.close' => 'Close a dialog',
    'shortcuts.help' => 'Show this list',
    'shortcuts.key_escape' => 'Esc',
    'shortcuts.new' => 'Add a subscription',
    'shortcuts.search' => 'Search the list',
    'shortcuts.title' => 'Keyboard shortcuts',

    // saved_views
    'saved_views.empty' => 'No saved views yet. Filter the list, then give the result a name.',
    'saved_views.forget' => 'Forget the view “{name}”',
    'saved_views.heading' => 'Saved views',
    'saved_views.name_label' => 'Name for this view',
    'saved_views.name_placeholder' => 'Streaming, shared bills…',
    'saved_views.save_current' => 'Save this view',

    // scenario — the planner, Phase 31
    'scenario.any_category' => 'Any category',
    'scenario.any_member' => 'Anybody',
    'scenario.any_tag' => 'Any tag',
    'scenario.by_row' => 'Change by change',
    'scenario.change_action' => 'Change the price',
    'scenario.change_name' => 'Change the price of {name}',
    'scenario.choice_cancel' => 'Cancel',
    'scenario.choice_change' => 'Change price',
    'scenario.choice_keep' => 'Keep',
    'scenario.choice_label' => 'What to do with {name}',
    'scenario.committed' =>
        '{count, plural, one {# charge still due} other {# charges still due}} ({charges}): '
        . 'the notice deadline ({deadline}) has passed',
    'scenario.costs_more' => 'Costs {amount} more',
    'scenario.cycle_days' => 'Every how many days',
    'scenario.difference' => 'Saving',
    'scenario.empty' => 'Nothing recurring is running, so there is nothing to plan.',
    'scenario.figure' => 'Figure',
    'scenario.figures_caption' => 'Spending now and with these changes',
    'scenario.filter' => 'Show',
    'scenario.filters_label' => 'Narrow the rows shown',
    'scenario.mine_run_rate_note' =>
        'The run-rate is the household’s; the next 12 months count only your share of each charge.',
    'scenario.monthly_saving' => 'Saving a month',
    'scenario.monthly_saving_note' => 'The run-rate, once every change has taken effect',
    'scenario.months_alt' => 'Spending each month now and with these changes, in {currency}',
    'scenario.months_heading' => 'Month by month',
    'scenario.new_cycle' => 'Billed',
    'scenario.new_price' => 'New price',
    'scenario.next_months' => 'Next {months} months',
    'scenario.no_matches' => 'No rows match these filters. Choices on hidden rows still count.',
    'scenario.no_saving_in_horizon' => 'No saving in the next {months} months',
    'scenario.now' => 'Now',
    'scenario.outcome_cancel' => 'Cancel',
    'scenario.outcome_change' => 'Change to {amount}',
    'scenario.outcome_change_cycle' => 'Change to {amount}, {cycle}',
    'scenario.reset' => 'Start again',
    'scenario.results_empty' => 'Choose Cancel or Change price on any row to see what it would save.',
    'scenario.results_heading' => 'What it would save',
    'scenario.results_note' => 'Worked out before anything is changed',
    'scenario.row_monthly' => '{amount} a month',
    'scenario.rows_heading' => 'Your subscriptions',
    'scenario.rows_note' => 'Everything running, kept unless you say otherwise',
    'scenario.run_rate_monthly' => 'Run-rate, a month',
    'scenario.run_rate_yearly' => 'Run-rate, a year',
    'scenario.same_cycle' => 'As now',
    'scenario.starts_on' => 'Saving starts {date}',
    'scenario.title' => 'Scenario',
    'scenario.twelve_saving' => 'Saving over the next {months} months',
    'scenario.twelve_saving_note' => 'Charge by charge, after any a notice period has committed',
    'scenario.unconvertible' =>
        '{count, plural, one {# row} other {# rows}} in {currency} cannot be combined — no rate',
    'scenario.with_changes' => 'With these changes',
    'scenario.work_it_out' => 'Work it out',

    // security
    'security.add_a_passkey' => 'Add a passkey',
    'security.authenticator_app' => 'Authenticator app',
    'security.passkey_name' => 'Passkey name',
    'security.passkey_name_label' => 'Name for the new passkey',
    'security.phone_yubikey_laptop' => 'Phone, YubiKey, laptop…',
    'security.recovery_codes_note' =>
        'Save these now — they are shown once and each works a single time. They are the way back in if '
        . 'you lose your authenticator.',
    'security.recovery_password_label' => 'Confirm your password to issue a new set',
    'security.recovery_remaining' =>
        '{count, plural, one {# unused code} other {# unused codes}}. Each works once, and they are the '
        . 'way back in if you lose your authenticator or every passkey you have registered.',
    'security.regenerate_recovery_codes' => 'Regenerate recovery codes',
    'security.sign_out_everywhere_else' => 'Sign out everywhere else',
    'security.this_device' => 'This device',
    'security.totp_off' => 'Off. Add an authenticator app to require a six-digit code as well as your password.',
    'security.totp_off_password_label' => 'Confirm your password to turn two-step verification off',
    'security.turn_off' => 'Turn off',
    'security.your_recovery_codes' => 'Your recovery codes',

    // security_totp_setup
    'security_totp_setup.confirm' => 'Turn on two-step verification',
    'security_totp_setup.enter_code' => 'Type the six-digit code it shows, to prove the app has the key.',
    'security_totp_setup.heading' => 'Set up your authenticator app',
    'security_totp_setup.scan' => 'Scan this code with your authenticator app, or enter the key by hand.',
    'security_totp_setup.setup_key' => 'Setup key:',
    'security_totp_setup.six_digit_code' => 'Six-digit code',
    'security_totp_setup.title' => 'Set up your authenticator',

    // settings
    'settings.allow_registration' => 'Allow anyone to create an account',
    'settings.api_key' => 'API key',
    'settings.base_currency' => 'Base currency',
    'settings.data_isolation' => 'Data isolation',
    'settings.demo_mode' => 'Read-only demonstration',
    'settings.demo_mode_hint' =>
        'Nothing on this instance can be changed while this is on — by anybody, through the browser or the '
        . 'API. Only an instance administrator can switch it off again, from this form.',
    'settings.exchange_rates' => 'Exchange rates',
    'settings.host_address_or_range' => 'Host, address or range',
    'settings.host_or_range' => 'Host or range',
    'settings.household' => 'Household',
    'settings.household_permission_note' => 'Only an owner or admin of this household can change these.',
    'settings.instance' => 'Instance',
    'settings.instance_note' => 'These apply to everybody on this instance.',
    'settings.rate_key_clear' => 'Remove the stored API key',
    'settings.rate_key_env_wins' => '{variable} in the environment overrides whatever is stored here.',
    'settings.rate_key_placeholder' => 'Leave blank to keep the current key',
    'settings.rates_need_key' =>
        '{provider} needs an API key and has not been given one, so no rates are being fetched.',
    'settings.rates_note' =>
        'Rates are cached and used only for display. Amounts stay in the currency they were entered in. '
        . 'When a rate is unavailable, totals are shown per currency instead of combined.',
    'settings.rates_stale' => 'Due a refresh.',
    'settings.role_for' => 'Role for {name}',
    'settings.save_household' => 'Save household',
    'settings.save_instance_settings' => 'Save instance settings',
    'settings.trust_this_host' => 'Trust this host',
    'settings.trusted_host_note_placeholder' => 'What this is, for later',
    'settings.trusted_host_placeholder' => 'gotify.lan, .lan, 192.168.1.10 or 100.64.0.0/10',
    'settings.status_outbound' => 'Outbound requests',
    'settings.outbound_open' => 'Open',
    'settings.outbound_open_hint' =>
        'Any public host can be reached, and a private one only if it is a trusted host. Set {variable} '
        . 'to restrict it. Email is not affected.',
    'settings.outbound_allowlist' => 'Allowed list only',
    'settings.outbound_allowlist_hint' =>
        'Only trusted hosts and the services Renovo is set up to use can be reached: the exchange-rate '
        . 'provider, and GitHub while update checks are on. Set by {variable}. Email is not affected.',
    'settings.outbound_off' => 'Off',
    'settings.outbound_off_hint' =>
        'Nothing is sent over HTTP: no logos, live rates, update checks or webhook and chat channels. '
        . 'Set by {variable}. Email is not affected.',
    'settings.trusted_kind' => 'Access',
    'settings.trusted_kind_private' => 'Reach a private address, over http',
    'settings.trusted_kind_contact' => 'Be contacted only',
    'settings.trusted_kind_hint' =>
        'Be contacted only is for a public host you want to allow while outbound requests are limited to '
        . 'an allowed list. Every other check on the address still applies to it.',
    'settings.trusted_hosts' => 'Trusted hosts',
    'settings.trusted_hosts_empty' => 'Nothing is trusted. Only public addresses can be reached.',
    'settings.trusted_hosts_exception' =>
        'A self-hosted Gotify on your LAN or on a Tailscale address is on the wrong side of that rule, '
        . 'so add it here. Each entry is an exception you have chosen, and every use of one is written to '
        . 'the log. A trusted host may also be reached over plain',
    'settings.trusted_hosts_intro' =>
        'Notifications and webhooks may only be sent to addresses on the public internet. That is what '
        . 'stops somebody using a webhook URL to make this server fetch something on your private network '
        . '— including, on a cloud host, the metadata service holding its credentials.',
    'settings.you' => '(you)',

    // setup_notifications
    'setup_notifications.add_another_channel' => 'Add another channel',
    'setup_notifications.add_another_hint' =>
        'Optional. Chat apps, push services and webhooks — pick one, fill in what it asks for, then send '
        . 'it a test.',
    'setup_notifications.email' => 'Email',
    'setup_notifications.intro' => 'Reminders go out before a renewal, a trial conversion or a cancel-by deadline.',
    'setup_notifications.later_note' =>
        'You can add more channels, or change any of this, later under Settings → Notifications. Every '
        . 'member configures their own.',
    'setup_notifications.mail_relay' => 'Mail goes out through {host}, from {from}. Those come from the environment:',
    'setup_notifications.other_channels' => 'Other channels',
    'setup_notifications.remind_me' => 'Remind me',
    'setup_notifications.send_test_email' => 'Send test email',
    'setup_notifications.send_test_to' => 'Send a test message to {label}',
    'setup_notifications.test_message_delivered' => 'Test message delivered.',
    'setup_notifications.title' => 'Set up notifications',
    'setup_notifications.where_should_reminders_go' => 'Where should reminders go?',

    // setup_wizard
    'setup_done.add_first' => 'Add your first subscription',
    'setup_done.currency' => 'Totals will show in {currency}.',
    'setup_done.go_to_dashboard' => 'Go to the dashboard',
    'setup_done.invite_later' => 'You can invite members any time from Members & roles.',
    'setup_done.invited' => 'Invitations went to {addresses}.',
    'setup_done.invites_failed' =>
        'These could not be invited, because an account now uses the address: {addresses}. Invite '
        . 'them again from Members & roles.',
    'setup_done.title' => '{household} is ready',
    'setup_wizard.create_owner' => 'Create the owner account',
    'setup_wizard.create_owner_intro' => 'You’ll manage members, backups and household settings.',
    'setup_wizard.currencies_all' => 'All currencies',
    'setup_wizard.currencies_common' => 'Common',
    'setup_wizard.finish_setup' => 'Finish setup',
    'setup_wizard.household' => 'Household',
    'setup_wizard.household_intro' => 'Name it, and choose the currency it counts in.',
    'setup_wizard.household_name' => 'Household name',
    'setup_wizard.household_title' => 'Name your household',
    'setup_wizard.invite_hint' => 'Invitees join as Contributors. You can change roles later.',
    'setup_wizard.invite_members' => 'Invite members (optional, comma-separated)',
    'setup_wizard.invite_placeholder' => 'alex@example.com, sam@example.com',
    'setup_wizard.more_options' => 'More options',
    'setup_wizard.reminders' => 'Reminders',
    'setup_wizard.step_of' => 'Step {step} of {count}',
    'setup_wizard.title' => 'Set up {instance}',
    'setup_wizard.your_account' => 'Your account',

    // state
    'state.active' => 'Active',
    'state.all' => 'All',
    'state.cancelled' => 'Cancelled',
    'state.expired' => 'Expired',
    'state.never' => 'Never',
    'state.none' => 'None',
    'state.paused' => 'Paused',
    'state.revoked' => 'Revoked',
    'state.shared' => 'Shared',
    'state.today' => 'Today',
    'state.tomorrow' => 'Tomorrow',
    'state.trial' => 'Trial',
    'state.trial_converts' => 'Trial converts',

    // stats
    'stats.breakdown' => 'Breakdown',
    'stats.converted' => 'Converted',
    'stats.kpi_ahead' => 'Next 12 months',
    'stats.kpi_ahead_note' => 'Forecast, including trials converting and scheduled price changes',
    'stats.kpi_average' => 'Average month',
    'stats.kpi_average_note' =>
        '{months, plural, one {# month} other {# months}} so far this year · '
        . '{count, plural, one {# active subscription} other {# active subscriptions}}',
    'stats.kpi_rises' => 'Price rises in {year}',
    'stats.kpi_rises_effect' => '{amount} a year',
    'stats.kpi_rises_none' => 'None recorded or scheduled',
    'stats.kpi_spent' => 'Spent this year',
    'stats.kpi_vs_last_year' => '{percent} vs the same period last year',
    'stats.months_alt' =>
        'Monthly spend in {currency}: twelve months reconstructed, this month so far and still due, '
        . 'and twelve months forecast.',
    'stats.months_heading' => 'Twelve months back, twelve months ahead',
    'stats.months_note' => 'Forecast includes trials converting and scheduled price changes',
    'stats.old_new' => 'Old → new',
    'stats.per_year' => 'Per year',
    'stats.price_history' => 'Price history',
    'stats.price_history_empty' => 'No price changes recorded yet.',
    'stats.price_history_note' =>
        'Every recorded change, newest first, in each subscription\'s own currency. A free trial ending '
        . 'is not a price change, and a currency conversion changes the currency, not the price.',
    'stats.price_history_status' => 'Price history, page {page} of {pages}',
    'stats.price_history_pages' => 'Price history pages',
    'stats.today' => 'Today',
    'stats.yoy_alt' => 'Spend in {currency} for each month of this year and last.',
    'stats.yoy_heading' => '{year} against {previous}',
    'stats.yoy_note' => 'Same month, year over year. The rest of this year is the forecast.',
    'stats.yoy_rolling' => 'Last 12 months {current}, against {previous} the 12 before:',
    'stats.cost_per_use' => 'Cost per use',
    'stats.donut_alt' =>
        'Doughnut chart of recurring monthly spend by category, in {currency}. The same figures are in '
        . 'the table that follows.',
    'stats.donut_per_currency' =>
        'Shown per currency rather than as one chart: no exchange rate is available for {currencies}, so '
        . 'there is no single total for the categories to be shares of. Each figure below is a share of '
        . 'its own currency\'s monthly total.',
    'stats.payment_methods_heading' => 'How it is paid',
    'stats.payment_methods_empty' => 'No recurring spend to break down by payment method yet.',
    'stats.payment_donut_alt' =>
        'Doughnut chart of recurring monthly spend by payment method, in {currency}. The same figures are in '
        . 'the table that follows.',
    'stats.payment_donut_per_currency' =>
        'Shown per currency rather than as one chart: no exchange rate is available for {currencies}, so '
        . 'there is no single total for the payment methods to be shares of. Each figure below is a share of '
        . 'its own currency\'s monthly total.',
    'stats.no_payment_method' => 'No payment method',
    'stats.other_payment_methods' => '{count, plural, one {# other method} other {# other methods}}',
    'stats.most_expensive' => 'Most expensive',
    'savings.title' => 'Savings',
    'savings.empty' => 'Nothing saved yet. An offer\'s charges, a payment marked as a discount and a cancelled subscription show here.',
    'savings.offers' => 'Saved through offers',
    'savings.cancellations' => 'Spend removed by cancellations',
    'savings.run_rate' => '{amount} a year taken off by cancellations in the last twelve months',
    'savings.trials' => '{count, plural, one {# trial} other {# trials}} cancelled before paying',
    'savings.breakdown' => 'Where it came from',
    'savings.offer_charges' => '{count, plural, one {# payment} other {# payments}}',
    'savings.cancelled_on' => 'cancelled {date}',
    'savings.avoided_charges' => '{count, plural, =0 {no payments avoided yet} one {# payment avoided} other {# payments avoided}}',
    'savings.committed_charges' => '{count, plural, one {# already due under its notice} other {# already due under its notice}}',
    'savings.row_run_rate' => '{amount} a year',
    'savings.note' => 'What offers and discounts took off the normal price, and what cancelled subscriptions would have cost since — counted for up to {months} months after each was cancelled, and never a charge its notice period had already committed. A fixed term that ran its course is not a saving.',
    'savings.saved_on_row' => 'Saved through offers:',
    'savings.see_all' => 'See where it came from',
    'stats.no_previous_year' =>
        'Nothing recorded for the year before last, so there is nothing to compare against. {amount} in '
        . 'the last twelve months.',
    'stats.no_uses_recorded' => 'No uses recorded',
    'stats.notable_empty' => 'Nothing with a monthly cost to compare yet.',
    'stats.notable_excluded' =>
        '{count, plural, one {# subscription is} other {# subscriptions are}} not ranked: no exchange '
        . 'rate is available to compare them with the rest.',
    'stats.notable_note' =>
        'Ranked on cost per month converted to the base currency, because two prices in different '
        . 'currencies have no order otherwise. Each is shown in the currency it is actually charged in. '
        . 'One-off and lifetime entries have no monthly cost, and a trial has none until it converts, so '
        . 'none of them is ranked.',
    'stats.per_period' => 'The same cost, by period',
    'stats.per_year_heading' => 'Recurring cost per year, by currency',
    'stats.per_year_note' =>
        'All four are derived from the yearly figure, so they always multiply up to one another. Days '
        . 'and weeks use the mean Gregorian year of 365.25 days. One-off and lifetime entries are '
        . 'excluded.',
    'stats.rarely_used' => 'Rarely used',
    'stats.rating_label' => '{rating} out of {max}',
    'stats.tab_forecast' => 'Forecast',
    'stats.tab_overview' => 'Overview',
    'stats.tab_scenario' => 'Scenario',
    'stats.tabs_label' => 'Analytics sections',
    'stats.title' => 'Analytics',
    'stats.unconvertible' =>
        'These cannot be combined into {currency}: no exchange rate is available for {currencies}. The '
        . 'per-currency figures below are the complete picture; a combined number leaving that spending '
        . 'out would not be.',
    'stats.used_it' => 'Used it',
    'stats.uses_per_month' => '{count} / month',
    'stats.value_empty' => 'No active recurring subscriptions to rank.',
    'stats.value_note' =>
        'Cost per use, highest first. Something with no uses recorded is unmeasured rather than poor '
        . 'value, and sorts to the bottom.',
    'stats.worth_it' => 'Worth it?',
    'stats.year_over_year_empty' => 'Not enough convertible data to compare the two years.',
    'stats.year_over_year_excluded' =>
        '{count, plural, one {# subscription has} other {# subscriptions have}} no start date and so '
        . 'contribute nothing to either year.',
    'stats.year_over_year_note' =>
        'From the payment ledger, with any amounts members recorded and without skipped charges. Months '
        . 'before a subscription’s first recorded payment are rebuilt from its start date, billing cycle and '
        . 'price history.',

    // subscriptions
    'subscriptions.add_subscription' => 'Add subscription',
    'subscriptions.apply_filters' => 'Apply filters',
    'subscriptions.cancel_by_all' => 'All deadlines',
    'subscriptions.cancel_by_empty' =>
        'Nothing with a notice period is due. Add one to a subscription and its deadline appears here.',
    'subscriptions.cancel_by_heading' => 'Cancel by',
    'subscriptions.cancel_by_note' =>
        'The last day to give notice and avoid the next charge. Shown only where a notice period is set — '
        . 'without one the deadline is the renewal date itself.',
    'subscriptions.categories_empty' => 'No recurring spend to break down yet.',
    'subscriptions.categories_heading' => 'Category spending',
    'subscriptions.categories_per_currency' =>
        'No exchange rate for {currencies}, so each currency is shown against its own total rather than '
        . 'blended into one bar.',
    'subscriptions.categories_total' => 'of {total} a month',
    'subscriptions.expiring_heading' => 'Expiring soon',
    'subscriptions.export' => 'Export',
    'subscriptions.filter_placeholder' => 'Filter subscriptions…',
    'subscriptions.include_paused' => 'Include paused',
    'subscriptions.name_or_notes' => 'Name or notes',
    'subscriptions.paused_heading' => 'Paused',
    'subscriptions.paused_if_resumed' => 'a year if resumed',
    'subscriptions.paused_note' => 'Switched off, and costing nothing while they are.',
    'subscriptions.renewing_note' => 'A charge falling in the next {days, plural, one {# day} other {# days}}.',
    'subscriptions.search' => 'Search',
    'subscriptions.strip' => 'Subscription totals',
    'subscriptions.notice_of' => '{period} notice',
    'subscriptions.scope' => 'Scope',
    'subscriptions.scope_mine' => 'Mine',
    'subscriptions.status_filter.active' => 'Active',
    'subscriptions.status_filter.cancelled' => 'Cancelled',
    'subscriptions.status_filter.paused' => 'Paused',
    'subscriptions.status_filter.trial' => 'Trials',
    'subscriptions.then_costs' => 'when it converts',
    'subscriptions.toolbar' => 'Filter the list',
    'subscriptions.trials_heading' => 'Trials',
    'subscriptions.trials_note' =>
        'The last day of a trial is the day of its first charge, so the countdown runs to the day it '
        . 'converts and the amount shown is what it converts to.',

    // subscriptions_form
    'subscriptions_form.at_todays_rate' => '≈ {amount} at today’s rate',
    'subscriptions_form.back_to_list' => 'Back to list',
    'subscriptions_form.belongs_to' => 'Belongs to',
    'subscriptions_form.billing_cycle' => 'Billing cycle',
    'subscriptions_form.cycle_kind_every' => 'Every…',
    'subscriptions_form.cycle_every' => 'Every',
    'subscriptions_form.cycle_unit' => 'Unit',
    'subscriptions_form.cycle_unit.weekly' => 'weeks',
    'subscriptions_form.cycle_unit.monthly' => 'months',
    'subscriptions_form.cycle_unit.yearly' => 'years',
    'subscriptions_form.anchor_last_day' => 'On the last day of the month',
    'subscriptions_form.anchor_last_day_hint' => 'Billed on the 31st, 30th or 28th — whichever ends the month.',
    'subscriptions_form.cancel_subscription' => 'Cancel subscription',
    'subscriptions_form.converts_to' => 'Converts to',
    'subscriptions_form.converts_to_cycle' => 'Converts to cycle',
    'subscriptions_form.converts_to_cycle_days' => 'Converts to days between payments',
    'subscriptions_form.converts_to_cycle_interval' => 'Converts to every how many',
    'subscriptions_form.converts_to_cycle_interval_hint' => 'Weeks, months or years, as its cycle. 1 for every one.',
    'subscriptions_form.converts_to_cycle_days_hint' => 'Only used when it converts to a custom cycle.',
    'subscriptions_form.converts_to_price_hint' => 'Leave blank if it converts to the price above.',
    'subscriptions_form.cost_split' => 'Cost split',
    'subscriptions_form.costs_and_usage' => 'Costs and usage',
    'subscriptions_form.cycle_days_hint' => 'Only used when the cycle is “Custom”.',
    'subscriptions_form.days_before' => '{days, plural, one {# day} other {# days}} before',
    'subscriptions_form.days_between_payments' => 'Days between payments',
    'subscriptions_form.edit_name' => 'Edit {name}',
    'subscriptions_form.edit_title' => 'Edit subscription',
    'subscriptions_form.end_it' => 'Stop paying for it',
    'subscriptions_form.every_n_days' => 'Every how many days',
    'subscriptions_form.free_trial' => 'Free trial',
    'subscriptions_form.is_promotional' => 'This is an introductory or promotional price',
    'subscriptions_form.is_promotional_note' => 'We’ll warn you before it goes up to the full price.',
    'subscriptions_form.is_trial' => 'This is a free trial',
    'subscriptions_form.is_trial_note' => 'We’ll remind you before it converts to a paid plan.',
    'subscriptions_form.isolated_owner_note' =>
        'This instance keeps members\' subscriptions separate, so new entries belong to you.',
    'subscriptions_form.offer_ends_hint' => 'The first day at the full price. Leave it blank if you don’t know yet.',
    'subscriptions_form.only_me_note' =>
        'Only you will see it, in either isolation mode — nobody else in the household, Owner/Admins '
        . 'included, and it stays out of their totals.',
    'subscriptions_form.only_me_split_note' =>
        'A shared cost is always visible to the people sharing it, so a split subscription cannot be '
        . 'kept to yourself.',
    'subscriptions_form.own_rows_owner_note' =>
        'Your role covers the entries you own, so new ones belong to you.',
    'subscriptions_form.logo' => 'Logo',
    'subscriptions_form.logo_hint' => 'Uploading a new file replaces it.',
    'subscriptions_form.amount_varies' => 'The amount varies',
    'subscriptions_form.amount_varies_note' =>
        'Gas, electricity, water: the price becomes an estimate, and figures that include it are shown “≈”.',
    'subscriptions_form.contract_ends_on' => 'Contract ends',
    'subscriptions_form.contract_ends_on_hint' =>
        'It keeps running after this. You’ll be reminded before the date, when it’s worth comparing prices.',
    'subscriptions_form.estimated_amount' => 'Estimated amount',
    'subscriptions_form.payments_end_on' => 'Payments end on',
    'subscriptions_form.payments_end_on_hint' =>
        'The last payment, for finance and fixed terms. It ends itself after the last one is made.',
    'subscriptions_form.terms' => 'Terms',
    'subscriptions_form.terms_hint' =>
        'Optional. A contract that ends, payments that stop, or an amount that changes every bill.',
    'subscriptions_form.more_details' => 'More details',
    'subscriptions_form.next_payment_date' => 'Next payment date',
    'subscriptions_form.no_rate_yet' => 'No exchange rate from {currency} to {base} yet.',
    'subscriptions_form.not_recorded' => 'Not recorded',
    'subscriptions_form.notice_hint' => 'Used to work out the last day you can cancel before the next charge.',
    'subscriptions_form.notice_period' => 'Notice period',
    'subscriptions_form.notice_period_unit' => 'Notice period unit',
    'subscriptions_form.notice_unit.days' => 'days',
    'subscriptions_form.notice_unit.months' => 'months',
    'subscriptions_form.notice_unit.weeks' => 'weeks',
    'subscriptions_form.only_me' => 'Only me',
    'subscriptions_form.paid_by' => 'Paid by',
    'subscriptions_form.paid_by_someone_else' => 'Paid by someone else',
    'subscriptions_form.paid_by_someone_else_hint' =>
        'Who actually pays, if it is not the member above. It changes nobody’s view of it.',
    'subscriptions_form.name_placeholder' => 'Netflix, Octopus Energy…',
    'subscriptions_form.plan_placeholder' => 'Standard, Family, Premium…',
    'subscriptions_form.remind_me' => 'Remind me',
    'subscriptions_form.remind_me_hint' => 'Days before each charge, and before a trial converts. Your defaults are the schedule in',
    'subscriptions_form.reminder_days' => 'Choose days',
    'subscriptions_form.reminder_default' => 'Use my defaults',
    'subscriptions_form.reminder_never' => 'Never',
    'subscriptions_form.reminders' => 'Reminders',
    'subscriptions_form.reminders_hint' =>
        'Days before the charge, for example {example} — or {never} to never be reminded about this one. '
        . 'Leave blank to use the schedule from',
    'subscriptions_form.reminders_hint_link' => 'your notification settings',
    'subscriptions_form.same_as_above' => 'Same as above',
    'subscriptions_form.save_changes' => 'Save changes',
    'subscriptions_form.section_billing' => 'When it’s billed',
    'subscriptions_form.section_cost' => 'What it costs',
    'subscriptions_form.section_identity' => 'What it is',
    'subscriptions_form.section_ownership' => 'Who it’s for',
    'subscriptions_form.start_date_hint' =>
        'Used to reconstruct what you spent in previous years, and to date the first entry in the price '
        . 'history.',
    'subscriptions_form.started_on' => 'Started on',
    'subscriptions_form.streaming_shared' => 'streaming, shared',
    'subscriptions_form.remove_tag' => 'Remove {name}',
    'subscriptions_form.service_name' => 'Service name',
    'subscriptions_form.split_custom' => 'Custom shares',
    'subscriptions_form.split_equal' => 'Split equally',
    'subscriptions_form.split_hint' =>
        'Shares are weights: 2 and 1 means two thirds and one third. The pennies always add up to the price.',
    'subscriptions_form.split_none' => 'Payer only',
    'subscriptions_form.tags_hint' => 'Comma separated. New tags are created automatically.',
    'subscriptions_form.trial_end_hint' => 'The last free day — and the day the first charge falls.',
    'subscriptions_form.trial_ends' => 'Trial ends',
    'subscriptions_form.trial_free_period_hint' => 'For a free period, use a trial instead.',
    'subscriptions_form.type_hint' => 'One-off and lifetime entries are tracked but left out of monthly totals.',
    'subscriptions_form.use_my_usual_reminders' => 'Use my usual reminders',

    'subscriptions_form.visible_to' => 'Visible to',
    'subscriptions_form.website_hint' =>
        'Used for the link on this subscription, and to fetch its icon if you have not uploaded one.',
    'subscriptions_form.website_outbound_allowlist' =>
        'Outbound requests are limited to an allowed list on this instance, so an icon is only fetched for a site on it.',
    'subscriptions_form.website_outbound_off' =>
        'Outbound requests are turned off on this instance, so no icon is fetched: upload one instead.',
    'subscriptions_form.website_placeholder' => 'https://example.com',

    // subscriptions_list
    'subscriptions_list.actions_for' => 'Actions for {name}',
    'subscriptions_list.add_tag' => 'Add tag',
    'subscriptions_list.add_the_first_one' => 'Add the first one.',
    'subscriptions_list.approximately' => '≈ {amount}',
    'subscriptions_list.estimate' => 'An estimate — the amount varies from bill to bill.',
    'subscriptions_list.cancel' => 'Cancel',
    'subscriptions_list.cancel_by' => 'Cancel by {date}',
    'subscriptions_list.cancel_name' => 'Cancel {name}',
    'subscriptions_list.cancel_trial' => 'Cancel trial',
    'subscriptions_list.convert_currency' => 'Convert currency',
    'subscriptions_list.convert_note' =>
        'Converting currency uses today\'s exchange rate and records the result in each subscription\'s '
        . 'price history. If a rate is unavailable the whole action is refused rather than re-labelling '
        . 'the amount, which would be a silent price change.',
    'subscriptions_list.cost' => 'Cost',
    'subscriptions_list.delete_name' => 'Delete {name}',
    'subscriptions_list.edit_name' => 'Edit {name}',
    'subscriptions_list.empty' => 'No subscriptions yet.',
    'subscriptions_list.in_days' => 'in {days, plural, one {# day} other {# days}}',
    'subscriptions_list.monthly_in' => 'Monthly ({currency})',
    'subscriptions_list.no_category' => 'No category',
    'subscriptions_list.no_matches' => 'Nothing matches those filters.',
    'subscriptions_list.no_rate' => 'No exchange rate for {currency}',
    'subscriptions_list.nobody' => 'Nobody',
    'subscriptions_list.not_amortised' => 'One-off and lifetime entries are not amortised',
    'subscriptions_list.pager' => 'Page {page} of {pages} · {total} total',
    'subscriptions_list.pagination' => 'Pagination',
    'subscriptions_list.pause' => 'Pause',
    'subscriptions_list.pause_name' => 'Pause {name}',
    'subscriptions_list.per_cycle.custom_days' => '/{days, plural, one {day} other {# days}}',
    'subscriptions_list.per_cycle.monthly' => '/{n, plural, one {mo} other {# mo}}',
    'subscriptions_list.per_cycle.quarterly' => '/qtr',
    'subscriptions_list.per_cycle.weekly' => '/{n, plural, one {wk} other {# wk}}',
    'subscriptions_list.per_cycle.yearly' => '/{n, plural, one {yr} other {# yr}}',
    'subscriptions_list.per_month' => '{amount}/mo',
    'subscriptions_list.remove_tag' => 'Remove tag',
    'subscriptions_list.resume' => 'Resume',
    'subscriptions_list.resume_name' => 'Resume {name}',
    'subscriptions_list.select' => 'Select',
    'subscriptions_list.select_all' => 'Select every subscription on this page',
    'subscriptions_list.select_one' => 'Select {name}',
    'subscriptions_list.plan_scenario' => 'Plan a scenario',
    'subscriptions_list.selected_count' => '{count} selected',
    'subscriptions_list.service' => 'Service',
    'subscriptions_list.set_category' => 'Set category',
    'subscriptions_list.set_member' => 'Set member',
    'subscriptions_list.set_payer' => 'Set payer',
    'subscriptions_list.split_custom' => 'Custom split',
    'subscriptions_list.split_equally_with' => 'Split equally with {name}',
    'subscriptions_list.split_ways' => 'Split {count, plural, one {# way} other {# ways}}',
    'subscriptions_list.status' => '{count, plural, one {# subscription matches} other {# subscriptions match}}',
    'subscriptions_list.summary' => '{matched, plural, other {#}} of {of, plural, other {#}}',
    'subscriptions_list.trial_ends' => 'Trial ends',
    'subscriptions_list.uncancel' => 'Undo cancel',
    'subscriptions_list.uncancel_name' => 'Undo cancelling {name}',
    'subscriptions_list.view_name' => 'View the costs of {name}',
    'subscriptions_list.with_selected' => 'With selected',

    // subscriptions_money
    'subscriptions_money.attach_a_file' => 'Attach a file',
    'subscriptions_money.attachment_hint' =>
        'PDF or an image. Stored outside the web root and only readable by people who can see this '
        . 'subscription.',
    'subscriptions_money.attachment_period_label' => 'Billing period it covers (optional)',
    'subscriptions_money.by_the_shares_below' => 'By the shares below',
    'subscriptions_money.change_split' => 'Change how it is split',
    'subscriptions_money.free_until' => 'Free until',
    'subscriptions_money.history_empty' => 'No price history recorded yet.',
    'subscriptions_money.history_note' =>
        'Every price is kept. A change is a new entry, never an edit, so what this cost last year stays '
        . 'knowable.',
    'subscriptions_money.how_it_is_split' => 'How it is split',
    'subscriptions_money.invoices_and_receipts' => 'Invoices and receipts',
    'subscriptions_money.it_converts_to' => 'It converts to',
    'subscriptions_money.its_owner' => 'its owner',
    'subscriptions_money.new_price' => 'New price',
    'subscriptions_money.no_rating' => 'No rating',
    'subscriptions_money.not_split' => 'Not split',
    'subscriptions_money.not_split_owner' => 'The whole cost sits with {owner}.',
    'subscriptions_money.note_optional' => 'Note (optional)',
    'subscriptions_money.nothing_attached_yet' => 'Nothing attached yet.',
    'subscriptions_money.price_history' => 'Price history',
    'subscriptions_money.price_note_placeholder' => 'Announced in their email of 3 March',
    'subscriptions_money.private_no_split' =>
        'Only you can see this subscription, so it is paid by you alone and cannot be split.',
    'subscriptions_money.reset_count' => 'Reset count',
    'subscriptions_money.save_rating' => 'Save rating',
    'subscriptions_money.save_split' => 'Save split',
    'subscriptions_money.schedule_a_price_change' => 'Schedule a price change',
    'subscriptions_money.schedule_change' => 'Schedule change',
    'subscriptions_money.schedule_note' =>
        'For an increase you have been told about but which has not happened yet. It is counted in '
        . 'forecasts and budgets from its date, and becomes the current price on the day it arrives.',
    'subscriptions_money.scheduled' => 'Scheduled',
    'subscriptions_money.shared_cost' => 'Shared cost',
    'subscriptions_money.shares_hint' =>
        'A share of 0 means that member is not part of the split. Shares are relative weights, not '
        . 'percentages — 2 and 1 means two thirds and one third.',
    'subscriptions_money.split_equal' => 'Equally between the members below',
    'subscriptions_money.split_note' =>
        'Shares are worked out from the current price every time, and always add up to it exactly — the '
        . 'odd penny goes to one member rather than disappearing.',
    'subscriptions_money.starts_on' => 'Starts on',
    'subscriptions_money.title' => 'Cost',
    'subscriptions_money.what_if_cancelled' => 'What if I cancelled this?',
    'subscriptions_money.trial_end_is_first_charge' =>
        'That is also the day the first charge falls — the last day of the trial, not the day after.',
    'subscriptions_money.usage' => 'Usage',
    'subscriptions_money.used_count' => 'Recorded {count, plural, one {# time} other {# times}}.',
    'subscriptions_money.used_since' => 'Recorded {count, plural, one {# time} other {# times}} since {since}.',

    // tokens
    'tokens.as_json' => 'as JSON',
    'tokens.bearer_note' => 'Send the token as a bearer credential:',
    'tokens.create_token' => 'Create token',
    'tokens.expires' => 'Expires',
    'tokens.expires_on_date' => 'On',
    'tokens.expires_on' => 'Expires {date}',
    'tokens.expiring_soon' => 'Expires soon',
    'tokens.intro' =>
        'A token lets a script, a calendar app or another machine reach this instance without a '
        . 'password. It can never do more than you can: a token issued by a Viewer reads what a Viewer '
        . 'reads and writes nothing.',
    'tokens.lifetime_custom' => 'On a date',
    'tokens.lifetime_days' => '{count, plural, one {# day} other {# days}}',
    'tokens.lifetime_hint' =>
        'Reissue a token before it expires to give it the same lifetime again from that day.',
    'tokens.lifetime_max_hint' =>
        'This instance allows tokens for at most {count, plural, one {# day} other {# days}}. '
        . 'Reissue one before it expires to give it the same lifetime again.',
    'tokens.lifetime_none' => 'No expiry',
    'tokens.name_placeholder' => 'Home Assistant, my calendar, a backup script',
    'tokens.never_expires' => 'Never expires',
    'tokens.new_token_note' =>
        'Copy it now. Only a hash of it is stored, so this is the one and only time it can be shown.',
    'tokens.no_scopes' => 'Nothing but who it belongs to',
    'tokens.none_yet' => 'None yet.',
    'tokens.reference_is_at' => 'The full reference is at',
    'tokens.scopes_hint' =>
        'Give it only what it needs. Whatever it holds, it can never do more than your role allows. '
        . 'The calendar feed refuses a token that can change anything.',
    'tokens.title' => 'API tokens',
    'tokens.using_them' => 'Using them',
    'tokens.what_it_is_for' => 'What it is for',
    'tokens.what_it_may_do' => 'What it may do',
    'tokens.your_new_token' => 'Your new token',

    // type
    'type.one_off' => 'One-off',

    // -----------------------------------------------------------------------
    // Vocabulary owned by the domain and the services: enum labels a template
    // asks for by key, the fields a notification channel declares, the text of
    // an alert, and the transactional emails.
    // -----------------------------------------------------------------------

    // alert
    'alert.budget.over' => 'That is {amount} over.',
    'alert.budget.projected' => 'Projected {projected} against a limit of {limit}.',
    'alert.budget.title' => 'Budget "{budget}" is projected to be exceeded',
    'alert.cancel.line' => 'That is {when} — the last day to give notice and avoid the next charge.',
    'alert.cancel.renews' => 'It renews at {amount}.',
    'alert.cancel.title' => 'Cancel {name} by {date}',
    'alert.renewal.line' => '{amount} is due on {date}.',
    'alert.renewal.line_estimate' => 'About {amount} is due on {date}.',
    'alert.renewal.title' => '{name} renews {when}',
    'alert.test.line' => 'If you are reading this, {channel} is configured correctly.',
    'alert.test.title' => 'Test notification',
    'alert.test.what_arrives' =>
        'Renewal reminders, trial warnings, cancellation deadlines and budget alerts will arrive '
        . 'here.',
    'alert.trial.line' => 'It starts charging {amount} on {date}.',
    'alert.trial.title' => '{name} trial ends {when}',
    'alert.when.in_days' => 'in {days, plural, one {# day} other {# days}}',
    'alert.when.today' => 'today',
    'alert.when.tomorrow' => 'tomorrow',

    // calendar
    'calendar.cancel_description' => 'Cancel {name} on or before this date to avoid the next charge of {amount}.',
    'calendar.cancel_summary' => 'Last day to cancel {name}',
    'calendar.feed_description' => 'Renewals, trial conversions and cancellation deadlines.',
    'calendar.feed_name' => '{instance} subscriptions',
    'calendar.payment_description' => '{name} is due ({amount}).',
    'calendar.payment_summary' => '{name} — {amount}',
    'calendar.trial_description' => 'The free trial of {name} ends and it converts to {amount}.',
    'calendar.trial_summary' => '{name} trial ends ({amount})',

    // capability — the rows of the members screen's role table, one per
    // RoleCapability. Each names a group of permissions a reader thinks of as
    // one thing.
    'capability.add_subscriptions' => 'Add subscriptions',
    'capability.edit_money' => 'Edit prices, splits & invoices',
    'capability.import_bulk_edit' => 'Import & bulk edit',
    'capability.manage_budgets' => 'Manage budgets',
    'capability.manage_household' => 'Members, backups & settings',
    'capability.manage_shared' => 'Categories, tags & payment methods',
    'capability.view_subscriptions' => 'View subscriptions & totals',

    // capability_grant — one cell of that table, always a word beside its icon.
    'capability_grant.no' => 'No',
    'capability_grant.own_only' => 'Own only',
    'capability_grant.yes' => 'Yes',

    // channel_field
    'channel_field.email.address' => 'Email address',
    'channel_field.email.address_hint' => 'Leave blank to use your account address.',
    'channel_field.discord.url' => 'Webhook URL',
    'channel_field.discord.url_hint' =>
        'Server Settings → Integrations → Webhooks → Copy Webhook URL. It is the credential, so it is '
        . 'stored like a password and not shown again.',
    'channel_field.gotify.priority' => 'Priority',
    'channel_field.gotify.priority_hint' => '0–10. Higher priorities ring.',
    'channel_field.gotify.token' => 'Application token',
    'channel_field.gotify.token_hint' => 'Created under Apps in Gotify.',
    'channel_field.gotify.url' => 'Server URL',
    'channel_field.gotify.url_hint' => 'For example https://gotify.example.com',
    'channel_field.mattermost.channel' => 'Channel',
    'channel_field.mattermost.channel_hint' =>
        'Optional. Overrides the webhook’s own channel. Use @username for a direct message.',
    'channel_field.mattermost.url' => 'Webhook URL',
    'channel_field.mattermost.url_hint' =>
        'From Integrations → Incoming Webhooks. A self-hosted server on a private address must be on the '
        . 'trusted-host list. The URL is the credential, so it is stored like a password and not shown again.',
    'channel_field.ntfy.priority' => 'Priority',
    'channel_field.ntfy.priority_hint' => '1–5. 3 is the default; 5 bypasses Do Not Disturb.',
    'channel_field.ntfy.server' => 'Server URL',
    'channel_field.ntfy.server_hint' =>
        'Leave blank for https://ntfy.sh. A self-hosted server on a private address must be on the '
        . 'trusted-host list.',
    'channel_field.ntfy.tags' => 'Tags',
    'channel_field.ntfy.tags_hint' => 'Optional, comma-separated. Emoji shortcodes such as warning become icons.',
    'channel_field.ntfy.token' => 'Access token',
    'channel_field.ntfy.token_hint' => 'Optional. Only needed for a protected topic.',
    'channel_field.ntfy.topic' => 'Topic',
    'channel_field.ntfy.topic_hint' =>
        'Anyone who knows a topic name on a public server can read it. Choose something hard to guess.',
    'channel_field.pushover.priority' => 'Priority',
    'channel_field.pushover.priority_hint' => '-2 silent to 1 high. 0 is the default.',
    'channel_field.pushover.token' => 'Application token',
    'channel_field.pushover.token_hint' => 'Created under Your Applications on pushover.net.',
    'channel_field.pushover.user_key' => 'User or group key',
    'channel_field.pushover.user_key_hint' => 'Shown on your Pushover dashboard.',
    'channel_field.pushplus.token' => 'Token',
    'channel_field.pushplus.token_hint' => 'From the pushplus.plus dashboard.',
    'channel_field.pushplus.topic' => 'Topic code',
    'channel_field.pushplus.topic_hint' => 'Optional. Sends to a group instead of your own account.',
    'channel_field.serverchan.sendkey' => 'SendKey',
    'channel_field.serverchan.sendkey_hint' =>
        'From sct.ftqq.com. It travels in the request URL, so it may appear in Server酱’s own logs.',
    'channel_field.slack.channel' => 'Channel or user',
    'channel_field.slack.channel_hint' =>
        'A channel id (C0123…), a channel name (#bills) or a user id (U0123…) for a direct message.',
    'channel_field.slack.token' => 'Bot token',
    'channel_field.slack.token_hint' => 'Starts with xoxb-. Needs the chat:write scope.',
    'channel_field.telegram.chat_id' => 'Chat id',
    'channel_field.telegram.chat_id_hint' =>
        'Message your bot, then open api.telegram.org/bot<token>/getUpdates to find the id. '
        . 'Negative for a group; @name works for a public channel.',
    'channel_field.telegram.token' => 'Bot token',
    'channel_field.telegram.token_hint' => 'Created by BotFather. Looks like 123456789:AA…',
    'channel_field.webhook.secret' => 'Shared secret',
    'channel_field.webhook.secret_hint' =>
        'Optional. Sent as an HMAC-SHA256 signature of the body in X-Renovo-Signature.',
    'channel_field.webhook.url' => 'Endpoint URL',

    // dashboard_card
    'dashboard_card.budget_pace' => 'Spent this year vs budget pace',
    'dashboard_card.budgets' => 'Budgets this month',
    'dashboard_card.by_category' => 'By category',
    'dashboard_card.coming_up' => 'Coming up',
    'dashboard_card.free_trials' => 'Free trials',
    'dashboard_card.month_so_far' => 'This month so far',
    'dashboard_card.next_30_days' => 'Next 30 days',
    'dashboard_card.price_change' => 'Next price change',
    'dashboard_card.recent' => 'Subscriptions table',
    'dashboard_card.savings' => 'Savings',
    'dashboard_card.spend_chart' => 'Monthly spend chart',
    'dashboard_card.spend_trend' => 'Spend over time',
    'dashboard_card.totals' => 'Spending at a glance',
    'dashboard_card.where_it_goes' => 'Where it goes',
    'dashboard_card.who_pays' => 'Who pays what',

    // dashboard_view
    'dashboard_view.household' => 'Household',
    'dashboard_view.overview' => 'Overview',

    // digest_mode
    'digest_mode.immediate' => 'As they happen',
    'digest_mode.monthly' => 'Monthly summary',
    'digest_mode.weekly' => 'Weekly summary',

    // error
    'error.api.attachment_missing' => 'That file is no longer stored.',
    'error.api.attachment_unreadable' => 'The attachment could not be read back.',
    'error.api.category_not_found' => 'No such category.',
    'error.api.payment_method_not_found' => 'No such payment method.',
    'error.api.rate_limited' =>
        'Too many requests. Try again in {seconds, plural, one {# second} other {# seconds}}.',
    'error.api.read_only_tokens_only' => 'This endpoint accepts read-only tokens only.',
    'error.api.subscription_not_found' => 'No such subscription.',
    'error.api.charge_not_found' => 'No such charge.',
    'error.api.file_part_missing' => 'Send the file as multipart form data in a "file" part.',
    'error.api.logo_part_missing' => 'Send the image as multipart form data in a "logo" part.',
    'error.api.subscription_unreadable' => 'The subscription could not be read back.',
    'error.api.tag_not_found' => 'No such tag.',
    'error.api.token_invalid' => 'That API token is not valid.',
    'error.api.token_scope' => 'This token does not have the "{scope}" scope this endpoint needs.',
    'error.api.token_scope_undeclared' => 'This endpoint does not say which token scope it needs, so it is refused.',
    'error.api.token_required' => 'This endpoint requires an API token.',
    'error.auth.registration_closed' => 'Registration is closed on this instance.',
    'error.auth.throttled_short' => 'Too many attempts. Try again shortly.',
    'error.auth.unverified_short' => 'Confirm your email address before signing in.',
    'error.csrf.expired' => 'The form has expired. Reload the page and try again.',
    'error.page.session_expired_title' => 'Session expired',
    'error.passkey.unexpected_response' => 'The browser sent an unexpected response.',
    'error.reminder_days.none_chosen' => 'Choose at least one day to be reminded, or pick “Use my defaults”.',
    'error.two_factor.expired' => 'That sign-in attempt expired.',
    'error.two_factor.restart_passkey' => 'Start the passkey step again.',

    // import_field
    'import_field.billing_cycle.hint' => 'weekly, monthly, quarterly, yearly, “every 6 months” or a number of days',
    'import_field.billing_cycle.label' => 'Billing cycle',
    'import_field.category.label' => 'Category',
    'import_field.converts_to_price.label' => 'Price after trial',
    'import_field.converts_to_price_minor.hint' =>
        'A whole number of pence or cents, for example 999. Takes precedence over the decimal '
        . 'price.',
    'import_field.converts_to_price_minor.label' => 'Price after trial, in minor units',
    'import_field.currency.label' => 'Currency',
    'import_field.cycle_days.label' => 'Days between payments',
    'import_field.cycle_interval.label' => 'Cycle interval',
    'import_field.cycle_interval.hint' => 'how many of the cycle’s unit: 6 with monthly is every six months',
    'import_field.amount_varies.hint' => 'yes/no — the price is then an estimate',
    'import_field.amount_varies.label' => 'Amount varies',
    'import_field.contract_ends_on.hint' => 'The day its contract ends; it keeps running after',
    'import_field.contract_ends_on.label' => 'Contract ends',
    'import_field.is_active.hint' => 'yes/no, true/false or 1/0',
    'import_field.is_active.label' => 'Active',
    'import_field.name.label' => 'Name',
    'import_field.next_payment_date.hint' => 'YYYY-MM-DD or DD/MM/YYYY',
    'import_field.next_payment_date.label' => 'Next payment date',
    'import_field.notes.label' => 'Notes',
    'import_field.notice_period_amount.label' => 'Notice period',
    'import_field.notice_period_unit.hint' => 'days, weeks or months',
    'import_field.notice_period_unit.label' => 'Notice period unit',
    'import_field.payments_end_on.hint' => 'The last payment of a fixed term',
    'import_field.payments_end_on.label' => 'Payments end on',
    'import_field.plan.label' => 'Plan',
    'import_field.price.hint' => 'A decimal amount, for example 9.99',
    'import_field.price.label' => 'Price',
    'import_field.price_minor.hint' =>
        'A whole number of pence or cents, for example 999. Takes precedence over the decimal '
        . 'price.',
    'import_field.price_minor.label' => 'Price in minor units',
    'import_field.start_date.hint' => 'YYYY-MM-DD or DD/MM/YYYY',
    'import_field.start_date.label' => 'Start date',
    'import_field.subscription_type.label' => 'Type',
    'import_field.tags.hint' => 'Separated by commas or semicolons',
    'import_field.tags.label' => 'Tags',
    'import_field.trial_end_date.hint' => 'YYYY-MM-DD or DD/MM/YYYY',
    'import_field.trial_end_date.label' => 'Trial end date',

    // mail
    'mail.password_changed.body' =>
        "Hello {name},\n\nYour password has just been changed. If this was not you, contact the "
        . "administrator of this instance immediately.\n\nAny API tokens and calendar feed links on your "
        . 'account have stopped working too. Create new ones if you still need them.',
    'mail.password_changed.subject' => 'Your {instance} password was changed',
    'mail.reset.body' =>
        "Hello {name},\n\nSomeone asked to reset the password for this account. If it was you, follow "
        . "this link within the next hour:\n\n{link}\n\nIf it was not you, no action is needed — the "
        . 'password has not changed.',
    'mail.reset.subject' => 'Reset your {instance} password',
    'mail.verify.body' =>
        "Hello {name},\n\nConfirm your email address to finish setting up your account:\n\n{link}\n\nThe "
        . 'link is valid for two days. If you did not create an account, you can ignore this message.',
    'mail.verify.subject' => 'Confirm your {instance} account',

    // rate_provider
    'rate_provider.exchangerate_host' =>
        'Free tier with a wider currency list than the ECB feed. Requires a free account and an '
        . 'access key.',
    'rate_provider.fixer' =>
        'Commercial service. Requires an access key; the free tier publishes EUR rates only, from '
        . 'which other bases are derived.',
    'rate_provider.frankfurter' =>
        'Free, no account needed. European Central Bank reference rates, updated each working day.',

    // relative — how long ago something happened, for a moment within the
    // last month. Older than that is shown as a date instead.
    'relative.days_ago' => '{count, plural, one {# day ago} other {# days ago}}',
    'relative.hours_ago' => '{count, plural, one {# hour ago} other {# hours ago}}',
    'relative.just_now' => 'Just now',
    'relative.minutes_ago' => '{count, plural, one {# minute ago} other {# minutes ago}}',

    // payment_methods
    'payment_methods.intro' =>
        'What your subscriptions are paid with. A payment method is only a label: Renovo never stores a card '
        . 'number and never takes a payment.',
    'payment_methods.none_yet' => 'No payment methods yet.',
    'payment_methods.defaults_hint' =>
        'Start from the usual list — cards, direct debit, PayPal and the rest — and rename or remove what you '
        . 'do not use.',
    'payment_methods.add_defaults' => 'Add the default list',
    'payment_methods.name_of' => 'Name of {name}',
    'payment_methods.colour_of' => 'Colour of {name}',
    'payment_methods.logo_of' => 'New logo for {name}',
    'payment_methods.colour_automatic' => 'Automatic colour',
    'payment_methods.colour_automatic_of' => 'Automatic colour for {name}',
    'payment_methods.clear_logo' => 'Remove logo',
    'payment_methods.new_method' => 'New payment method',
    'payment_methods.logo' => 'Logo',
    'payment_methods.logo_hint' =>
        'Optional. PNG, JPEG, GIF or WebP. Without one, the method is shown with a generic icon.',
    'payment_methods.add_method' => 'Add payment method',
    'payment_methods.default.credit_card' => 'Credit Card',
    'payment_methods.default.debit_card' => 'Debit Card',
    'payment_methods.default.direct_debit' => 'Direct Debit',
    'payment_methods.default.bank_transfer' => 'Bank Transfer',
    'payment_methods.default.standing_order' => 'Standing Order',
    'payment_methods.default.paypal' => 'PayPal',
    'payment_methods.default.cash' => 'Cash',
    'payment_methods.default.gift_card' => 'Gift Card',
    'payment_methods.default.app_store' => 'App Store',
    'payment_methods.default.google_play' => 'Google Play',

    // token_preset, token_scope
    'token_preset.calendar' => 'Calendar only',
    'token_preset.custom' => 'Custom',
    'token_preset.read' => 'Read everything',
    'token_preset.write' => 'Read and write everything',
    'token_scope.calendar_read' => 'Calendar feed',
    'token_scope.calendar_read_hint' => 'Read the iCalendar feed of upcoming payments.',
    'token_scope.catalogue_read' => 'Read categories, tags and payment methods',
    'token_scope.catalogue_read_hint' => 'List them and their logos.',
    'token_scope.catalogue_write' => 'Change categories, tags and payment methods',
    'token_scope.catalogue_write_hint' => 'Add, rename, delete and change logos. Includes reading them.',
    'token_scope.subscriptions_read' => 'Read subscriptions',
    'token_scope.subscriptions_read_hint' => 'Subscriptions, their attachments, payments and savings.',
    'token_scope.subscriptions_write' => 'Change subscriptions',
    'token_scope.subscriptions_write_hint' =>
        'Add, edit, cancel and delete them, their attachments and payments. Includes reading them.',

    'visibility.household' => 'Household',
    'visibility.payer' => 'Only me',

    // -----------------------------------------------------------------------
    // Phase 27: the profile
    // -----------------------------------------------------------------------
    'profile.appearance_heading' => 'Appearance & preferences',
    'profile.new_recovery_codes' => 'New recovery codes',
    'profile.passkey_added' => 'Passkey · added {date}',
    'profile.passkey_remove' => 'Remove {name}',
    'profile.session_address_unknown' => 'Unknown address',
    'profile.session_meta' => '{address} · last seen {seen}',
    'profile.session_sign_out' => 'Sign out {device}',
    'profile.sessions_heading' => 'Where you’re signed in',
    'profile.totp_on' => 'On',
    'profile.totp_on_since' => 'On since {date} · {remaining} of {total} recovery codes left',
    'profile.totp_set_up' => 'Set up',
    'profile.two_step_heading' => 'Two-step verification & passkeys',

    // -----------------------------------------------------------------------
    // Phase 28: settings and notifications
    // -----------------------------------------------------------------------
    'action.add' => 'Add',
    'confirm.delete_channel' => 'Remove {name}? Nothing more will be sent to it.',
    'confirm.delete_tag' => 'Delete {name}? It comes off every subscription that carries it.',
    'error.tag.duplicate' => 'A tag with that name already exists.',
    'error.tag.too_long' => 'A tag must be 50 characters or fewer.',
    'error.tag.too_long_50' => 'Each tag must be 50 characters or fewer.',
    'error.tags.too_many_25' => 'Use at most 25 tags on one subscription.',
    'flash.channel_turned_off' => 'Channel turned off.',
    'flash.channel_turned_on' => 'Channel turned on.',
    'flash.rates_backing_off' => 'The last refresh failed, so the next can be tried after {time}.',
    'flash.rates_refresh_failed' => 'The rates could not be refreshed: {reason}',
    'flash.rates_refreshed' => '{count, plural, one {# rate} other {# rates}} refreshed.',
    'flash.tag_added' => 'Tag added.',
    'flash.tag_renamed' => 'Tag renamed.',
    'notifications.budget_alerts' => 'Budget alerts',
    'notifications.budget_alerts_hint' => 'When a budget is projected over.',
    'notifications.channel_manage' => 'Edit {name}',
    'notifications.channel_switch' => 'Send to {name}',
    'notifications.days_before' => '{count, plural, one {# day} other {# days}}',
    'notifications.lead_times' => 'Remind me before',
    'notifications.lead_times_hint' =>
        'Before each {types}. Choose any number, or none; each is a reminder of its own. A single '
        . 'subscription can override this on its own page.',
    'notifications.route_label' => '{alert} to {channel}',
    'notifications.routing' => 'Routing',
    'notifications.when_to_remind' => 'When to remind you',
    'settings.added_by' => 'by {name}',
    'settings.allow_registration_hint' =>
        'Off, and accounts are made only by invitation from a household’s Owner or Admin.',
    'settings.backup_heading' => 'Backup & restore',
    'settings.base_currency_hint' => 'Totals, budgets and forecasts are shown in this currency.',
    'settings.base_currency_instance_note' => 'It applies to every household on this instance.',
    'settings.categories_intro' => 'Shared by the whole household. Renaming one renames it everywhere.',
    'settings.delete_named' => 'Delete {name}',
    'settings.export_csv' => 'CSV',
    'settings.export_heading' => 'Export',
    'settings.export_intro' =>
        'Every subscription you can see, paused and cancelled ones included, as a spreadsheet or as JSON. '
        . 'The importer reads either back.',
    'settings.export_json' => 'JSON',
    'settings.export_payments_intro' => 'Every payment in the ledger, with the subscription it belongs to.',
    'settings.export_payments_csv' => 'Payments CSV',
    'settings.export_payments_json' => 'Payments JSON',
    'settings.full_audit_log' => 'Full audit log',
    'settings.household_name' => 'Household name',
    'settings.import_note' => 'You map the columns and preview every row before anything is saved.',
    'settings.import_title' => 'Import a CSV or JSON file',
    'settings.instance_status' => 'This server',
    'settings.instance_status_intro' => 'Set in the environment, and shown here as it is.',
    'settings.new_tag' => 'New tag',
    'settings.new_token' => 'New token',
    'settings.rate' => 'Rate',
    'settings.rate_pair' => 'Pair',
    'settings.rate_pair_value' => '1 {from} → {to}',
    'settings.rate_provider' => 'Provider',
    'settings.rates_backing_off' => 'The last refresh failed. The next can be tried after {time}.',
    'settings.rates_from' => 'Rates from {provider}.',
    'settings.rates_last_refreshed' => 'Last refreshed {when}.',
    'settings.rates_never' => 'Not refreshed yet.',
    'settings.rates_none_in_use' => 'Every subscription is priced in {base}, so no rate is needed.',
    'settings.rates_others' =>
        '{count, plural, one {# other currency is} other {# other currencies are}} cached as well.',
    'settings.recent_activity' => 'Recent activity',
    'settings.refresh_now' => 'Refresh now',
    'settings.reissue_named' => 'Reissue {name}',
    'settings.remove_named' => 'Remove {name}',
    'settings.rename_category' => 'Name of {name}',
    'settings.rename_tag' => 'Name of {name}',
    'settings.restore_from_file' => 'Restore from file',
    'settings.revoke_named' => 'Revoke {name}',
    'settings.save_currency' => 'Save currency',
    'settings.save_provider' => 'Save provider',
    'settings.set_by_instance_admin' => 'It is set by the instance administrator.',
    'settings.status_mail' => 'Mail relay',
    'settings.status_api_tokens' => 'API token lifetime',
    'settings.status_api_tokens_max' => 'At most {count, plural, one {# day} other {# days}}, set by {variable}',
    'settings.status_api_tokens_no_max' => 'No maximum',
    'settings.status_api_tokens_outstanding' => 'They were issued before the maximum was set: reissue or revoke them.',
    'settings.status_api_tokens_set_max' => 'Set {variable} to make every new token expire.',
    'settings.status_api_tokens_without_expiry' =>
        '{count, plural, =0 {Every token expires.} one {# token never expires.} other {# tokens never expire.}}',
    'settings.status_mail_encryption' => 'Encryption: {encryption}',
    'settings.status_mail_no_sign_in' => 'no sign-in',
    'settings.status_mail_signs_in' => 'signs in',
    'settings.status_metrics' => 'Metrics',
    'settings.status_metrics_off' => 'Off. Set {variable} to expose /metrics.',
    'settings.status_metrics_on' => '/metrics answers a request that carries the token.',
    'settings.status_secrets_key' => 'Encryption key',
    'settings.status_secrets_key_set' => 'Own key',
    'settings.status_secrets_key_set_hint' => 'Stored secrets have a key of their own, so the session key can change freely.',
    'settings.status_secrets_key_unset' => 'Shared with sessions',
    'settings.status_secrets_key_unset_hint' =>
        'Set {variable}. Until then, stored secrets are encrypted under {session}, and changing it would '
        . 'make every notification channel and authenticator app unreadable.',
    'settings.status_scheduler' => 'Scheduler last ran',
    'settings.status_scheduler_never' => 'Not yet',
    'settings.subscriptions_count' => '{count, plural, one {subscription} other {subscriptions}}',
    'settings.tab_data' => 'Data & integrations',
    'settings.tab_general' => 'General',
    'settings.tab_instance' => 'Instance',
    'settings.tabs_label' => 'Settings sections',
    'settings.tags_intro' =>
        'Usually made by typing one on a subscription. Renaming one renames it everywhere; deleting one '
        . 'takes it off every subscription and deletes none of them.',
    'settings.token_last_used' => 'last used {when}',
    'settings.token_never_used' => 'never used',
    'state.off' => 'Off',
    'state.on' => 'On',

    // -----------------------------------------------------------------------
    // Phase 37: the version, and knowing when there is a newer one
    // -----------------------------------------------------------------------
    'flash.updates_available' => 'Renovo {version} is available.',
    'flash.updates_check_failed' => 'The update check failed. The log says why.',
    'flash.updates_no_releases' => 'Checked. No release has been published yet.',
    'flash.updates_off' => 'Update checks are off.',
    'flash.updates_too_soon' => 'An update check ran less than a minute ago. Try again shortly.',
    'flash.updates_up_to_date' => 'Checked. This is the latest release.',
    'settings.status_version' => 'Version',
    'settings.update_banner' => 'Show a banner on the dashboard when an update is available',
    'settings.update_banner_hint' =>
        'Instance administrators only, once update checks are on. Each can dismiss it until the next release.',
    'settings.update_check' => 'Check for new versions',
    'settings.update_check_hint' =>
        'Once a day, Renovo asks GitHub for its latest release. GitHub sees your server\'s IP address. '
        . 'Nothing else is sent.',
    'settings.updates' => 'Updates',
    'shell.version' => 'Renovo {version}',
    'updates.available' => '{version} available',
    'updates.banner' => 'Renovo {latest} is available. You\'re running {current}.',
    'updates.check_now' => 'Check now',
    'updates.checked_at' => 'Checked {date}',
    'updates.dismiss' => 'Dismiss',
    'updates.last_check_failed' => 'Last check failed {date}',
    'updates.never_checked' => 'Not checked yet',
    'updates.new_tab' => '(opens in a new tab)',
    'updates.no_releases' => 'No release published yet',
    'updates.release_notes' => 'Release notes',
    'updates.unavailable_environment' => 'Turned off for this server by UPDATE_CHECK=false.',
    'updates.unavailable_repository' =>
        'Off: UPDATE_CHECK_REPOSITORY is not an owner/name, so there is nothing to check.',
    'updates.up_to_date' => 'Up to date',

    // Phase 38: instance backups.
    'action.download' => 'Download',
    'audit_action.instance_backup.downloaded' => 'Instance backup downloaded',
    'audit_action.instance_backup.failed' => 'Instance backup failed',
    'audit_action.instance_backup.pruned' => 'Old instance backups pruned',
    'audit_action.instance_backup.taken' => 'Instance backup taken',
    'backup.download_named' => 'Download the instance backup from {date}',
    'backup.duration' => '{seconds, plural, one {# second} other {# seconds}}',
    'backup.instance_back_up_now' => 'Back up now',
    'backup.instance_back_up_now_hint' => 'The page waits while the backup is taken and checked.',
    'backup.instance_directory' => 'Stored in',
    'backup.instance_heading' => 'Instance backups',
    'backup.instance_history' => 'Recent backups',
    'backup.instance_history_empty' => 'No instance backup has been taken yet.',
    'backup.instance_intro' =>
        'The whole database and every stored file, for disaster recovery: encrypted, checked after it is '
        . 'written, and pruned by the counts below. Restoring one is a command, not a button. A household '
        . 'backup, for moving one household, is under Data & integrations.',
    'backup.instance_keep_daily' => 'Daily backups to keep',
    'backup.instance_keep_monthly' => 'Monthly backups to keep',
    'backup.instance_keep_weekly' => 'Weekly backups to keep',
    'backup.instance_key' => 'Encryption key',
    'backup.instance_key_invalid' => 'Not valid',
    'backup.instance_key_invalid_hint' =>
        '{variable} must be 64 hexadecimal characters. Generate one with openssl rand -hex 32.',
    'backup.instance_key_missing' => 'Not set',
    'backup.instance_key_missing_hint' =>
        'Set {variable} to enable instance backups. Generate one with openssl rand -hex 32.',
    'backup.instance_key_set' => 'Set',
    'backup.instance_keys_elsewhere' =>
        'Store these keys somewhere that is not this server: {backup} to open a backup, and {session}, '
        . '{secrets}, {previous} and {totp} for the restored instance to read its two-factor and channel '
        . 'settings. A backup cannot be restored without them.',
    'backup.instance_retention' => 'Keep',
    'backup.instance_retention_hint' =>
        'The newest backup of each of the last so many days, weeks and months. The newest verified backup '
        . 'is always kept.',
    'backup.instance_same_server' =>
        'These backups are on the same server as your data. Copy {variable} off this machine as well.',
    'backup.instance_save' => 'Save backup settings',
    'backup.instance_schedule' => 'Schedule',
    'backup.key_short' => 'key',
    'backup.reason.abandoned' => 'It stopped before it finished.',
    'backup.reason.checksum' => 'A file in it does not match its checksum.',
    'backup.reason.io' => 'A file could not be read.',
    'backup.reason.key_invalid' => 'BACKUP_ENCRYPTION_KEY is not a valid key.',
    'backup.reason.key_missing' => 'BACKUP_ENCRYPTION_KEY is not set.',
    'backup.reason.manifest_invalid' => 'Its manifest is missing or incomplete.',
    'backup.reason.manifest_mismatch' => 'Its contents and its manifest disagree.',
    'backup.reason.migration_mismatch' => 'It was taken at a different schema version.',
    'backup.reason.not_a_backup' => 'The file is not an instance backup.',
    'backup.reason.not_an_archive' => 'It decrypts to something that is not an archive.',
    'backup.reason.not_empty' => 'The database is not empty.',
    'backup.reason.row_count' => 'A table holds a different number of rows from its manifest.',
    'backup.reason.row_unreadable' => 'A row in it cannot be read.',
    'backup.reason.tampered' => 'It does not decrypt: it has been altered or damaged.',
    'backup.reason.truncated' => 'It is incomplete.',
    'backup.reason.unexpected' => 'Something unexpected went wrong. The log says what.',
    'backup.reason.unexpected_entry' => 'It holds a file no instance backup contains.',
    'backup.reason.unsupported_version' => 'It was written in a format this version cannot read.',
    'backup.reason.unverifiable' => 'It does not verify.',
    'backup.reason.write_failed' => 'It could not be written. Is the disk full?',
    'backup.reason.wrong_key' => 'It was made with a different key.',
    'backup.schedule.daily' => 'Daily',
    'backup.schedule.off' => 'Off',
    'backup.schedule.weekly' => 'Weekly',
    'backup.size_kb' => '{size} KB',
    'backup.size_mb' => '{size} MB',
    'backup.status.failed' => 'Failed',
    'backup.status.pruned' => 'Pruned',
    'backup.status.running' => 'Running',
    'backup.status.verified' => 'Verified',
    'backup.trigger.manual' => 'By hand',
    'backup.trigger.schedule' => 'Scheduled',
    'backup.warning.more' => 'More warnings, not listed here (the backup\'s manifest has them all):',
    'backup.warning.missing_file' => 'A file a row names was not there:',
    'backup.warning.unrecognised_path' => 'A stored path was not one Renovo writes, so it was left out:',
    'backup.warnings' => '{count, plural, one {# warning} other {# warnings}}',
    'flash.instance_backup_busy' => 'An instance backup is already running.',
    'flash.instance_backup_failed' => 'The instance backup failed. {reason}',
    'flash.instance_backup_settings_saved' => 'Backup settings saved.',
    'flash.instance_backup_taken' => 'Backed up and verified.',
    'flash.instance_backup_too_soon' => 'An instance backup started less than a minute ago. Try again shortly.',
    'settings.status_instance_backup' => 'Last instance backup',
    'settings.status_instance_backup_bad_key' => 'Off: {variable} is not a valid key',
    'settings.status_instance_backup_never' => 'Not yet',
    'settings.status_instance_backup_no_key' => 'Off: {variable} is not set',

    // Phase 39: backups off the server, and rehearsing a restore.
    'alert.backup_failed' => 'Backup failed',
    'alert.backup_failed.line' => 'The instance backup started {started} failed: {reason}',
    'alert.backup_failed.title' => 'An instance backup failed',
    'alert.backup_offsite_failed.line' =>
        'The backup started {started} could not be sent to {count, plural, one {its destination} '
        . 'other {any of its # destinations}} after three attempts.',
    'alert.backup_offsite_failed.local' => 'It is still on this server, and only there.',
    'alert.backup_offsite_failed.title' => 'A backup could not be sent off the server',
    'alert.backup_rehearsal_failed' => 'Restore rehearsal failed',
    'alert.backup_rehearsal_failed.line' =>
        'Rehearsing a restore from {destination} failed while it was {step}.',
    'alert.backup_rehearsal_failed.title' => 'A restore rehearsal failed',
    'alert.backup_stale' => 'No backup off the server',
    'alert.backup_stale.line' => 'Nothing has been stored off the server since {since}.',
    'alert.backup_stale.never' => 'No backup has been stored off the server yet.',
    'alert.backup_stale.title' => 'Backups are not reaching a destination',
    'audit_action.backup_destination.added' => 'Backup destination added',
    'audit_action.backup_destination.changed' => 'Backup destination changed',
    'audit_action.backup_destination.removed' => 'Backup destination removed',
    'backup.copy.confirmed' => 'Stored',
    'backup.copy.failed' => 'Failed',
    'backup.copy.pending' => 'Waiting to send',
    'backup.copy.pruned' => 'Pruned there',
    'backup.copy.removed_destination' => 'A removed destination',
    'backup.destination.add' => 'Add a destination',
    'backup.destination.credential_replace' => 'Replace',
    'backup.destination.credential_replace_hint' => 'Leave blank to keep the one stored.',
    'backup.destination.credential_set' => 'Set',
    'backup.destination.credentials_unreadable' =>
        'Its credentials cannot be opened with the configured encryption keys. Enter them again.',
    'backup.destination.edit_named' => 'Edit {name}',
    'backup.destination.enabled_named' => 'Send backups to {name}',
    'backup.destination.field.access_key_id' => 'Access key ID',
    'backup.destination.id' => 'ID {id}',
    'backup.destination.field.bucket' => 'Bucket',
    'backup.destination.field.endpoint' => 'Endpoint',
    'backup.destination.field.endpoint_hint' =>
        'For example https://s3.eu-west-2.amazonaws.com or https://s3.us-west-004.backblazeb2.com. '
        . 'A server on your own network must be on the trusted-host list.',
    'backup.destination.field.name' => 'Name',
    'backup.destination.field.password' => 'Password',
    'backup.destination.field.path' => 'Directory',
    'backup.destination.field.path_hint' =>
        'An absolute path inside the container, on a mounted share or a second disk.',
    'backup.destination.field.path_style' => 'Path-style addresses',
    'backup.destination.field.path_style_hint' => 'Needed by MinIO and most self-hosted stores.',
    'backup.destination.field.prefix' => 'Prefix',
    'backup.destination.field.prefix_hint' => 'Put before every object name, for example renovo/.',
    'backup.destination.field.region' => 'Region',
    'backup.destination.field.secret_key' => 'Secret access key',
    'backup.destination.field.url' => 'Folder URL',
    'backup.destination.field.url_hint' =>
        'The WebDAV address of a folder, for example https://cloud.example.com/remote.php/dav/files/me/backups/.',
    'backup.destination.field.username' => 'Username',
    'backup.destination.heading' => 'Destinations',
    'backup.destination.intro' =>
        'Every verified backup is sent to each destination that is switched on, encrypted before it '
        . 'leaves: a destination only ever holds what it cannot read.',
    'backup.destination.last_upload' => 'Last stored {date}',
    'backup.destination.never_uploaded' => 'Nothing stored yet',
    'backup.destination.none' => 'No destination yet. Backups stay on this server only.',
    'backup.destination.remove' => 'Remove',
    'backup.destination.remove_confirm' =>
        'Remove {name}? Renovo stops sending backups there. Nothing already stored there is deleted.',
    'backup.destination.remove_named' => 'Remove {name}',
    'backup.destination.retention' => 'Keep {daily} daily, {weekly} weekly, {monthly} monthly',
    'backup.destination.retention_hint' =>
        'Counted for this destination alone. Its newest copy is always kept.',
    'backup.destination.save' => 'Save destination',
    'backup.destination.test' => 'Test connection',
    'backup.destination.test_named' => 'Test connection to {name}',
    'backup.destination.type.directory' => 'Directory',
    'backup.destination.type.s3' => 'S3-compatible',
    'backup.destination.type.webdav' => 'WebDAV',
    'backup.destination_reason.blocked' => 'Renovo may not connect there. A private address must be on the trusted-host list.',
    'backup.destination_reason.checksum' => 'The copy stored does not match the backup.',
    'backup.destination_reason.denied' => 'The credentials were refused.',
    'backup.destination_reason.full' => 'The destination is full.',
    'backup.destination_reason.missing' => 'The directory does not exist.',
    'backup.destination_reason.mismatch' => 'What was read back is not what was written.',
    'backup.destination_reason.not_found' => 'The bucket, folder or copy was not found.',
    'backup.destination_reason.not_writable' => 'The directory is not writable.',
    'backup.destination_reason.redirect' => 'It answered with a redirect, which Renovo does not follow. Enter the address it points to.',
    'backup.destination_reason.refused' => 'The destination refused the request.',
    'backup.destination_reason.size' => 'The size stored is not the size sent.',
    'backup.destination_reason.transport' => 'It could not be reached.',
    'backup.destination_reason.unreadable' => 'Its credentials cannot be opened with the configured keys.',
    'backup.destination_reason.unexpected' => 'Something unexpected went wrong. The log says what.',
    'backup.destination_reason.write_failed' => 'The copy could not be written.',
    'backup.destination_step.connect' => 'connecting',
    'backup.destination_step.delete' => 'deleting the test file',
    'backup.destination_step.read' => 'reading it back',
    'backup.destination_step.verify' => 'checking it',
    'backup.destination_step.write' => 'writing a test file',
    'backup.history_offsite' => 'Off the server',
    'backup.instance_back_up_now_hint_offsite' =>
        'The page waits while the backup is taken and checked; it is sent to the destinations after.',
    'backup.instance_offsite_stale' => 'Nothing has reached a destination for two schedule periods.',
    'backup.instance_sending' => 'Sending',
    'backup.instance_stored_offsite' => 'Stored off the server',
    'backup.instance_verified_locally' => 'Verified locally',
    'backup.reason.password_hash' => 'A restored password hash is not one Renovo writes.',
    'backup.reason.scratch_is_application' => 'The scratch database is the application\'s own database.',
    'backup.reason.scratch_not_empty' => 'The scratch database is not empty.',
    'backup.reason.secret_unreadable' => 'A restored secret does not open with this instance\'s keys.',
    'backup.rehearsal.database' => 'Scratch database',
    'backup.rehearsal.database_configured' => 'Configured',
    'backup.rehearsal.database_missing' =>
        'Not configured: rehearsals download and verify a copy, without restoring it. Set {variable} '
        . 'to an empty database to restore into.',
    'backup.rehearsal.database_set' => 'Rehearsals restore into it and drop it again.',
    'backup.rehearsal.heading' => 'Restore rehearsal',
    'backup.rehearsal.history' => 'Recent rehearsals',
    'backup.rehearsal.intro' =>
        'A backup nobody has restored is a hope. A rehearsal downloads the newest copy from a destination '
        . '— not the one on this server — and checks it can be decrypted and, with a scratch database, '
        . 'restored.',
    'backup.rehearsal.kind.download' => 'Downloaded and verified',
    'backup.rehearsal.kind.restore' => 'Restored and checked',
    'backup.rehearsal.last' => 'Last rehearsal',
    'backup.rehearsal.never' => 'Not rehearsed yet',
    'backup.rehearsal.no_destination' => 'Add a destination first: a rehearsal restores from one.',
    'backup.rehearsal.save' => 'Save rehearsal schedule',
    'backup.rehearsal.schedule' => 'Rehearse',
    'backup.rehearsal.schedule.monthly' => 'Monthly',
    'backup.rehearsal.schedule.off' => 'Off',
    'backup.rehearsal.schedule.weekly' => 'Weekly',
    'backup.rehearsal.status.failed' => 'Failed',
    'backup.rehearsal.status.passed' => 'Passed',
    'backup.rehearsal.status.running' => 'Running',
    'backup.rehearsal.step.checking' => 'checking the restored data',
    'backup.rehearsal.step.cleaning' => 'emptying the scratch database',
    'backup.rehearsal.step.downloading' => 'downloading the copy',
    'backup.rehearsal.step.migrating' => 'preparing the scratch database',
    'backup.rehearsal.step.restoring' => 'restoring',
    'backup.rehearsal.step.unknown' => 'running',
    'backup.rehearsal.step.verifying' => 'verifying the copy',
    'error.backup_destination.bucket' => 'Enter the bucket\'s name.',
    'error.backup_destination.credential' => 'Enter this credential.',
    'error.backup_destination.credential_moved' =>
        'Enter the credentials again: the destination now points somewhere else, and saved ones are never sent to a new address.',
    'error.backup_destination.endpoint' => 'Enter the endpoint as an https:// address, with no path.',
    'error.backup_destination.missing' => 'That destination no longer exists.',
    'error.backup_destination.name' => 'Enter a name of up to 100 characters.',
    'error.backup_destination.path' => 'Enter an absolute path, starting with /.',
    'error.backup_destination.path_inside' =>
        'Choose a directory outside Renovo\'s own: its application, data, uploads and BACKUP_DIRECTORY.',
    'error.backup_destination.path_missing' => 'That directory does not exist inside the container.',
    'error.backup_destination.path_unwritable' => 'Renovo cannot write to that directory.',
    'error.backup_destination.prefix' => 'Use letters, numbers and / - _ . ! * \' ( ) only.',
    'error.backup_destination.region' => 'Enter the region as letters, numbers and dashes, or leave it blank.',
    'error.backup_destination.type' => 'Choose what kind of destination this is.',
    'error.backup_destination.url' => 'Enter the folder\'s address, starting with https://.',
    'error.backup_destination.username' => 'Enter the username.',
    'flash.backup_destination_added' => 'Destination added. Test the connection to be sure it works.',
    'flash.backup_destination_removed' => 'Destination removed. Nothing stored there was deleted.',
    'flash.backup_destination_saved' => 'Destination saved.',
    'flash.backup_destination_test_failed' => 'The connection test failed while {step}: {reason}',
    'flash.backup_destination_test_passed' => 'Connected: a test file was written, read back and deleted.',
    'flash.backup_rehearsal_saved' => 'Rehearsal schedule saved.',
    'flash.instance_backup_taken_queued' =>
        'Backed up and verified. Another upload is running, so the scheduler will send this one.',
    'flash.instance_backup_taken_sending' =>
        'Backed up and verified. Sending it to {count, plural, one {# destination} other {# destinations}} now.',
    'notifications.backup_failed_alerts' => 'A backup fails, or reaches no destination',
    'notifications.backup_failed_alerts_hint' => 'Once for each backup that did not verify or could not be sent off the server.',
    'notifications.backup_heading' => 'Instance backups',
    'notifications.backup_hint' => 'Only instance administrators are told these.',
    'notifications.backup_rehearsal_alerts' => 'A restore rehearsal fails',
    'notifications.backup_rehearsal_alerts_hint' => 'With the step it failed at: downloading, verifying or restoring.',
    'notifications.backup_stale_alerts' => 'Nothing reaches a destination for two schedule periods',
    'notifications.backup_stale_alerts_hint' => 'Once, until a backup is stored off the server again.',
];
