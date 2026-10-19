<?php

/**
 * Canyon Framework - Social Plugin
 *
 * This plugin adds social networking features to the Canyon Framework:
 * - User Profiles with avatars and cover photos
 * - Friend/Connection system with requests and blocking
 * - Private messaging system
 * - Activity feed (wall posts, likes, comments, shares)
 * - User Groups with membership and admin controls
 *
 * Install: Copy this plugin to /plugins/social/ or enable via admin panel
 * Database: Run migrations (see database/migrations/social_*.php)
 */

use Canyon\Core\Plugin;

return [
    'plugin' => [
        'name' => 'Social System',
        'description' => 'Complete social networking platform with profiles, messaging, and groups',
        'version' => '1.0.0',
        'author' => 'Canyon Framework Contributors',
        'active' => true,
        'requires' => [],
        'services' => [
            'Waf' => WafService::class,
            'Message' => MessageService::class,
            'Activity' => ActivityService::class,
        ],
        'migrations' => [
            '2024_01_01_create_user_profiles_table',
            '2024_01_01_create_friend_requests_table',
            '2024_01_01_create_messages_table',
            '2024_01_01_create_activity_feed_table',
            '2024_01_01_create_groups_table',
            '2024_01_01_create_group_members_table',
            '2024_01_01_create_group_posts_table',
        ],
    ],

    // Routes - registered with the router
    'routes' => [
        'social.profile.show' => [
            'path' => '/profile/{username}',
            'method' => 'GET',
            'handler' => [UserProfileController::class, 'show'],
        ],
        'social.messages.index' => [
            'path' => '/messages',
            'method' => 'GET',
            'middleware' => ['auth'],
        ],
        'social.messages.store' => [
            'path' => '/messages',
            'method' => 'POST',
            'middleware' => ['auth', 'csrf'],
        ],
        'social.groups.index' => [
            'path' => '/groups',
            'method' => 'GET',
        ],
        'social.groups.create' => [
            'path' => '/groups/create',
            'method' => ['GET', 'POST'],
            'middleware' => ['auth'],
        ],
    ],

    // Middleware
    'middleware' => [
        'auth' => AuthMiddleware::class,
        'guest' => GuestMiddleware::class,
    ],

    // Widgets
    'widgets' => [
        'activity_feed' => [
            'name' => 'Activity Feed',
            'render' => 'social::widgets.activity-feed',
            'position' => 'sidebar',
            'options' => ['limit' => 10],
        ],
        'friend_requests' => [
            'name' => 'Friend Requests',
            'render' => 'social::widgets.friend-requests',
            'position' => 'notification_badge',
        ],
        'online_users' => [
            'name' => 'Online Users',
            'render' => 'social::widgets.online-users',
            'position' => 'sidebar',
        ],
    ],

    // Hooks - called at specific points
    'hooks' => [
        'user.created' => 'SocialPlugin::onUserCreated',
        'user.deleted' => 'SocialPlugin::onUserDeleted',
        'post.created' => 'SocialPlugin::onPostCreated',
        'like.created' => 'SocialPlugin::onLikeCreated',
    ],

    // Assets
    'assets' => [
        'css' => [
            'social::assets/css/social.css',
        ],
        'js' => [
            'social::assets/js/social.js',
        ],
    ],

    // Configuration
    'config' => [
        'max_friends' => 5000,
        'max_messages_per_day' => 100,
        'feed_post_ttl' => 86400,
        'rate_limit_messages' => 10,
    ],
];