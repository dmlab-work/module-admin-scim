<?php
/**
 * Copyright © DMLab. All rights reserved.
 * Licensed under the Open Software License version 3.0 (OSL-3.0). See LICENSE.
 *
 * Bodies the core receives after provider normalization (the core side of the
 * provider-plugin seam). Okta and Google ship no normalizer: their raw bodies
 * must pass the core as-is. Provider plugins may assert their outputs against these.
 */
declare(strict_types=1);

$enterprise = 'urn:ietf:params:scim:schemas:extension:enterprise:2.0:User';

return [
    // name => [Operations, expected admin_user fields set by the patch]
    'user_patch' => [
        'okta: path-less active false' => [
            [['op' => 'replace', 'value' => ['active' => false]]],
            ['is_active' => 0],
        ],
        'okta: path-less active "false"' => [
            [['op' => 'replace', 'value' => ['active' => 'false']]],
            ['is_active' => 0],
        ],
        'okta: active path "true"' => [
            [['op' => 'replace', 'path' => 'active', 'value' => 'true']],
            ['is_active' => 1],
        ],
        'google: path-less active false' => [
            [['op' => 'replace', 'value' => ['active' => false]]],
            ['is_active' => 0],
        ],
        'google: path-less active "false"' => [
            [['op' => 'replace', 'value' => ['active' => 'false']]],
            ['is_active' => 0],
        ],
        'google: active path "true"' => [
            [['op' => 'replace', 'path' => 'active', 'value' => 'true']],
            ['is_active' => 1],
        ],
        'entra: deactivate with title in one body' => [
            [
                ['op' => 'Replace', 'path' => 'active', 'value' => 'False'],
                ['op' => 'Replace', 'path' => 'title', 'value' => 'Eng'],
            ],
            ['is_active' => 0],
        ],
        'entra: work email value filter' => [
            [['op' => 'Replace', 'path' => 'emails[type eq "work"].value', 'value' => 'a@x.io']],
            ['email' => 'a@x.io'],
        ],
        'entra: enterprise extension object' => [
            [['op' => 'Add', 'path' => $enterprise, 'value' => ['department' => 'R&D', 'employeeNumber' => '7']]],
            [],
        ],
        'entra: core URN path with object value' => [
            [[
                'op' => 'Replace',
                'path' => 'urn:ietf:params:scim:schemas:core:2.0:User',
                'value' => ['userName' => 'jdoe@x.io'],
            ]],
            ['dmlab_scim_user_name' => 'jdoe@x.io', 'username' => 'jdoe@x.io'],
        ],
        'entra: path-less flat keys with extension' => [
            [[
                'op' => 'Replace',
                'value' => [
                    'displayName' => 'J Doe',
                    'name.givenName' => 'J',
                    $enterprise => ['manager' => ['value' => '9']],
                ],
            ]],
            ['firstname' => 'J'],
        ],
        'entra: phone number value filter' => [
            [['op' => 'Add', 'path' => 'phoneNumbers[type eq "work"].value', 'value' => '+1']],
            [],
        ],
        'entra: remove enterprise manager' => [
            [['op' => 'Remove', 'path' => $enterprise . ':manager']],
            [],
        ],
    ],
    // name => [POST body, expected active]
    'user_create' => [
        'google: active "False"' => [
            [
                'schemas' => ['urn:ietf:params:scim:schemas:core:2.0:User'],
                'userName' => 'jdoe@x.io',
                'active' => 'False',
            ],
            false,
        ],
    ],
    // name => [initial members, Operations, expected members, expected displayName]
    'group_patch' => [
        'entra: remove member by value filter' => [
            [42, 43],
            [['op' => 'Remove', 'path' => 'members[value eq "42"]']],
            [43],
            'G',
        ],
        'entra: add members' => [
            [42],
            [['op' => 'Add', 'path' => 'members', 'value' => [['value' => '43']]]],
            [42, 43],
            'G',
        ],
        'entra: replace displayName' => [
            [42],
            [['op' => 'Replace', 'path' => 'displayName', 'value' => 'Ops']],
            [42],
            'Ops',
        ],
        'entra: empty member remove as no-op add' => [
            [42],
            [['op' => 'add', 'path' => 'members', 'value' => []]],
            [42],
            'G',
        ],
        'okta: path-less rename with id' => [
            [42],
            [['op' => 'replace', 'value' => ['id' => '5', 'displayName' => 'Ops']]],
            [42],
            'Ops',
        ],
        'okta: remove member by value filter' => [
            [42, 43],
            [['op' => 'remove', 'path' => 'members[value eq "42"]']],
            [43],
            'G',
        ],
    ],
];
