<?php
/**
 * Plugin Name: WP GraphQL Meta Box Custom Fields (TGHP Version)
 * Description: Exposes all registered Meta Box Custom Fields to the WPGraphQL EndPoint.
 * Author: TGHP / Niklas Dahlqvist
 * Version: 2.3.0
 * License: GPL2+
 */

namespace WPGraphQL\Extensions;

use RWMB_Image_Field;
use RWMB_Video_Field;
use WPGraphQL\Data\Connection\PostObjectConnectionResolver;

if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('\WPGraphQL\Extensions\MB')) {
    class MB
    {
        /**
         * List of media fields to filter.
         *
         * @var array
         */
        static $media_fields = [
            'media',
            'file',
            'file_upload',
            'file_advanced',
            'single_image',
            'image',
            'image_upload',
            'image_advanced',
            'plupload_image',
            'thickbox_image',
            'video',
        ];

        /**
         * List of post fields to filter
         *
         * @var array
         */
        static $post_fields = [
            'post',
        ];

        /**
         * List of taxonomy fields to filter
         *
         * @var array
         */
        static $taxonomy_fields = [
            'taxonomy_advanced',
        ];

        /**
         * List of group fields to filter
         *
         * @var array
         */
        static $group_fields = [
            'group',
        ];

        public function __construct()
        {
            $this->add_extra_types();
            $this->add_meta_boxes_to_graphQL();
            $this->add_page_template_connection();
            $this->add_cloudinary_to_mediaitem();
        }

        public function add_meta_boxes_to_graphQL()
        {
            // Add fields for meta boxes relating to posts
            foreach ($this->get_types('post') as $type => $object) {
                if (isset($object->graphql_single_name)) {
                    add_action('graphql_register_types', function ($fields) use ($type, $object) {
                        $this->add_meta_fields(
                            $this->get_post_type_meta_boxes($type),
                            $fields,
                            $type,
                            $object->graphql_single_name
                        );
                    });
                }
            }

            // Add fields for meta boxes relating to taxonomies
            foreach ($this->get_types('taxonomy') as $type => $object) {
                if (isset($object->graphql_single_name)) {
                    add_action('graphql_register_types', function ($fields) use ($type, $object) {
                        $this->add_meta_fields(
                            $this->get_term_meta_boxes($type),
                            $fields,
                            $type,
                            $object->graphql_single_name
                        );
                    });
                }
            }

            if (class_exists('\WPGraphQLGutenberg\Schema\Types\BlockTypes')) {
                // Add fields for meta boxes relating to blocks
                foreach ($this->get_types('post_with_blocks') as $type => $object) {
                    if (isset($object->graphql_single_name)) {
                        add_action('graphql_register_types', function ($fields) use ($type, $object) {
                            $this->add_block_meta_fields(
                                $this->get_block_meta_boxes(),
                                $fields,
                                $type,
                            );
                        });
                    }
                }
            }

            // Add fields for meta boxes relating to settings
            add_action('graphql_register_types', function () {
                $settingsPages = apply_filters('mb_settings_pages', []);
                $metaboxesBySettingsPage = [];

                foreach ($settingsPages as $settingsPage) {
                    $metaboxesBySettingsPage[$settingsPage['id']] = [
                        'id' => $settingsPage['id'],
                        'option_name' => $settingsPage['option_name'],
                        'metaboxes' => [],
                    ];
                }

                $settingsMetaboxes = $this->get_settings_meta_boxes();

                /** @var \MBSP\MetaBox $settingMetabox */
                foreach ($settingsMetaboxes as $settingsMetabox) {
                    $settingsPageIds = $settingsMetabox->settings_pages;

                    if (!is_array($settingsPageIds)) {
                        $settingsPageIds = [$settingsPageIds];
                    }

                    foreach ($settingsPageIds as $settingsPageId) {
                        $metaboxesBySettingsPage[$settingsPageId]['metaboxes'][] = $settingsMetabox;
                    }
                }

                foreach ($metaboxesBySettingsPage as $settingsPageId => $settingsPageData) {
                    $this->add_settings_meta_fields(
                        $settingsPageData['metaboxes'],
                        $settingsPageId,
                        $settingsPageData['option_name'],
                        self::_graphql_label($settingsPageData['option_name']) . 'MetaboxSettings',
                    );
                }
            });
        }

        public function add_extra_types()
        {
            $settingsMetaboxes = $this->get_settings_meta_boxes();
            $settingsFields = [];

            foreach ($settingsMetaboxes as $settingsMetabox) {
                $settingsPages = $settingsMetabox->settings_pages;

                if (!is_array($settingsPages)) {
                    $settingsPages = [$settingsPages];
                }

                foreach ($settingsPages as $settingsPage) {
                    if (!isset($settingsFields[self::_graphql_label($settingsPage)])) {
                        $settingsFields[self::_graphql_label($settingsPage)] = [];
                    }

                    foreach($settingsMetabox->meta_box['fields'] as $field) {
                        if (empty($field['id'])) {
                            continue;
                        }

                        if (in_array($field['type'], self::$media_fields)) {
                            if ($field['clone'] == true || $field['multiple'] == true) {
                                $fieldDefinition = [
                                    'type' => ['list_of' => 'MediaItem'],
                                ];
                            } else {
                                $fieldDefinition = [
                                    'type' => 'MediaItem',
                                ];
                            }
                        } else if (in_array($field['type'], self::$taxonomy_fields)) {
                            if ($field['clone'] == true || $field['multiple'] == true) {
                                $fieldDefinition = [
                                    'type' => ['list_of' => 'Term'],
                                ];
                            } else {
                                $fieldDefinition = [
                                    'type' => 'Term',
                                ];
                            }
                        } else {
                            if ($field['clone'] == true || $field['multiple'] == true) {
                                $fieldDefinition = [
                                    'type' => ['list_of' => 'String'],
                                ];
                            } else {
                                $fieldDefinition = [
                                    'type' => 'String',
                                ];
                            }
                        }

                        $fieldDefinition['description'] = 'Metabox setting - ' . $field['id'];
                        $settingsFields[self::_graphql_label($settingsPage)][self::_graphql_label($field['id'])] = $fieldDefinition;
                    }
                }
            }

            foreach ($settingsFields as $settingsFieldsPage => $fields) {
                register_graphql_object_type(ucfirst($settingsFieldsPage . 'MetaboxSettings'), [
                    'description' => 'Metabox settings for settings page: ' . $settingsFieldsPage,
                    'fields' => $fields,
                ]);
            }

            register_graphql_object_type('Term', [
                'description' => 'Term object',
                'fields' => [
                    'id' => [
                        'type' => 'ID',
                        'description' => 'The ID of the term object.',
                    ],
                    'name' => [
                        'type' => 'String',
                        'description' => 'The name of the term object.',
                    ],
                    'slug' => [
                        'type' => 'String',
                        'description' => 'The slug of the term object.',
                    ],
                    'description' => [
                        'type' => 'String',
                        'description' => 'The description of the term object.',
                    ],
                    'taxonomy' => [
                        'type' => 'String',
                        'description' => 'The taxonomy of the term object.',
                    ],
                ],
            ]);
        }

        public function add_meta_fields($boxes, $fields, $object_type, $graphql_single_name)
        {
            $graphql_single_name = ucfirst($graphql_single_name);

            foreach ($boxes as $box) {
                foreach ($box->fields as $field) {
                    if (empty($field['id'])) {
                        continue;
                    }

                    $field_name = self::_graphql_label($field['id']);

                    if ($box->get_object_type() === 'term') {
                        if (!isset($box->meta_box['taxonomies']) || empty($box->meta_box['taxonomies'])) {
                            continue;
                        }

                        if (!in_array($object_type, $box->meta_box['taxonomies'])) {
                            continue;
                        }
                    }

                    if (in_array($field['type'], self::$group_fields)) {
                        $group_type_name = ucfirst(self::_graphql_label($field['id']));
                        $group_fields = [];
                        $image_group_fields = [];
                        $post_group_fields = [];

                        foreach ($field['fields'] as $group_sub_field) {
                            if (empty($group_sub_field['id'])) {
                                continue;
                            }

                            if (in_array($group_sub_field['type'], self::$media_fields)) {
                                $image_group_fields[] = $group_sub_field;
                            } else if (in_array($group_sub_field['type'], self::$post_fields)) {
                                $post_group_fields[] = $group_sub_field;
                            } else {
                                $group_fields[self::_graphql_label($group_sub_field['id'])] = [
                                    'type' => 'String',
                                    'description' => "Group field - {$group_sub_field['name']}",
                                ];
                            }
                        }

                        register_graphql_object_type($group_type_name, [
                            'description' => "Metabox Group {$group_type_name} object",
                            'fields' => $group_fields,
                        ]);

                        if (!empty($image_group_fields)) {
                            foreach ($image_group_fields as $image_group_field) {
                                register_graphql_connection([
                                    'fromType' => $group_type_name,
                                    'toType' => 'MediaItem',
                                    'fromFieldName' => self::_graphql_label($image_group_field['id']),
                                    'resolve' => function( $item, $args, $context, $info ) use ($object_type, $image_group_field) {
                                        $id = self::_graphql_label($image_group_field['id']);
                                        if (isset($item[$id]) && is_array($item[$id])) {
                                            if (($image_group_field['clone'] == true || $image_group_field['multiple'] == true)) {
                                                $ids = array_map(function ($image) {
                                                    return $image['ID'];
                                                }, $item[$id]);
                                            } else {
                                                $ids = [$item[$id]['ID']];
                                            }

                                            $resolver = new \WPGraphQL\Data\Connection\PostObjectConnectionResolver($item, $args, $context, $info, 'attachment');
                                            $resolver->set_query_arg('post__in', $ids);
                                            $resolver->set_query_arg('orderby', 'post__in');

                                            return $resolver->get_connection();
                                        }
                                    },
                                ]);
                            }
                        }

                        if (!empty($post_group_fields)) {
                            foreach ($post_group_fields as $post_group_field) {
                                if (!empty($post_group_field['post_type']) && is_array($post_group_field['post_type'])) {
                                    $post_type_name = $post_group_field['post_type'][0];
                                    $post_type = get_post_type_object($post_type_name);

                                    if ($post_type && property_exists($post_type, 'graphql_single_name')) {
                                        $post_type_graphql_name = $post_type->graphql_single_name;

                                        register_graphql_connection([
                                            'fromType' => $group_type_name,
                                            'toType' => $post_type_graphql_name,
                                            'fromFieldName' => self::_graphql_label($post_group_field['id']),
                                            'resolve' => function( $item, $args, $context, $info ) use ($object_type, $post_group_field, $post_type) {
                                                $id = self::_graphql_label($post_group_field['id']);
                                                if (isset($item[$id])) {
                                                    if (($post_group_field['clone'] == true || $post_group_field['multiple'] == true)) {
                                                        $ids = $item[$id];
                                                    } else {
                                                        $ids = [$item[$id]];
                                                    }

                                                    $resolver = new \WPGraphQL\Data\Connection\PostObjectConnectionResolver($item, $args, $context, $info, $post_type->name);
                                                    $resolver->set_query_arg('post__in', $ids);
                                                    $resolver->set_query_arg('orderby', 'post__in');

                                                    return $resolver->get_connection();
                                                }
                                            },
                                        ]);
                                    }
                                }
                            }
                        }

                        if (($field['clone'] == true || $field['multiple'] == true)) {
                            register_graphql_field($graphql_single_name, $field_name, [
                                'type' => ['list_of' => $group_type_name],
                                'description' => $field['desc'],
                                'resolve' => function ($object) use ($object_type, $field) {
                                    $meta = self::_get_meta_value($field, $object, $object_type);
                                    return $meta;
                                },
                            ]);
                        } else {
                            register_graphql_field($graphql_single_name, $field_name, [
                                'type' => $group_type_name,
                                'description' => $field['desc'],
                                'resolve' => function ($object) use ($object_type, $field) {
                                    $meta = self::_get_meta_value($field, $object, $object_type);
                                    return $meta;
                                },
                            ]);
                        }
                    } else if (in_array($field['type'], self::$media_fields)) {
                        // TODO: Does single_image work?
                        register_graphql_connection([
                            'fromType' => $graphql_single_name,
                            'toType' => 'MediaItem',
                            'fromFieldName' => $field_name,
                            'resolve' => function($item, $args, $context, $info ) use ($object_type, $field) {
                                if ($item instanceof \WPGraphQL\Model\Post) {
                                    $meta = self::_get_meta_value($field, $item->ID, $object_type);
                                } else if ($item instanceof \WPGraphQL\Model\Term) {
                                    $meta = self::_get_meta_value($field, $item, $object_type);
                                }

                                if (($field['clone'] == true || $field['multiple'] == true)) {
                                    $ids = array_map(function ($image) {
                                        return $image['ID'];
                                    }, $meta);
                                } else {
                                    $ids = [$meta['ID']];
                                }

                                if (!empty($ids)) {
                                    $resolver = new \WPGraphQL\Data\Connection\PostObjectConnectionResolver($item, $args, $context, $info, 'attachment');
                                    $resolver->set_query_arg('post__in', $ids);
                                    $resolver->set_query_arg('orderby', 'post__in');

                                    return $resolver->get_connection();
                                }
                            },
                        ]);
                    } else if (in_array($field['type'], self::$taxonomy_fields)) {
                        if ($field['clone'] == false && $field['multiple'] == false) {
                            register_graphql_field($graphql_single_name, $field_name, [
                                'type' => 'Term',
                                'description' => $field['desc'],
                                'resolve' => function ($object) use ($object_type, $field) {
                                    $meta = self::_get_meta_value($field, $object, $object_type);
                                    $meta = self::_convert_wp_internal($meta);

                                    return $meta;
                                },
                            ]);
                        }

                        if (($field['clone'] == true || $field['multiple'] == true)) {
                            register_graphql_field($graphql_single_name, $field_name, [
                                'type' => ['list_of' => 'Term'],
                                'description' => $field['desc'],
                                'resolve' => function ($object) use ($object_type, $field) {
                                    $meta = self::_get_meta_value($field, $object, $object_type);

                                    foreach ($meta as &$metaValue) {
                                        $metaValue = self::_convert_wp_internal($metaValue);
                                    }

                                    return $meta;
                                },

                            ]);
                        }
                    } else if (in_array($field['type'], self::$post_fields)) {
                        if (!empty($field['post_type']) && is_array($field['post_type'])) {
                            $post_type_name = $field['post_type'][0];
                            $post_type = get_post_type_object($post_type_name);

                            if ($post_type && property_exists($post_type, 'graphql_single_name')) {
                                $post_type_graphql_name = $post_type->graphql_single_name;

                                register_graphql_connection([
                                    'fromType' => $graphql_single_name,
                                    'toType' => $post_type_graphql_name,
                                    'fromFieldName' => $field_name,
                                    'resolve' => function( $item, $args, $context, $info ) use ($object_type, $field, $post_type) {
                                        if ($item instanceof \WPGraphQL\Model\Post) {
                                            $meta = self::_get_meta_value($field, $item->ID, $object_type);
                                        } else if ($item instanceof \WPGraphQL\Model\Term) {
                                            $meta = self::_get_meta_value($field, $item, $object_type);
                                        }

                                        if (isset($field['post_type'])) {
                                            if (($field['clone'] == true || $field['multiple'] == true)) {
                                                $ids = $meta;
                                            } else {
                                                $ids = [$meta];
                                            }
                                        } else {
                                            $ids = [];
                                        }

                                        if (!empty($ids)) {
                                            $resolver = new \WPGraphQL\Data\Connection\PostObjectConnectionResolver($item, $args, $context, $info, $field['post_type'] ?: 'post');
                                            $resolver->set_query_arg('post__in', $ids);
                                            $resolver->set_query_arg('orderby', 'post__in');

                                            return $resolver->get_connection();
                                        }
                                    },
                                ]);
                            }
                        }
                    } else {
                        if (($field['clone'] == true || $field['multiple'] == true)) {
                            register_graphql_field($graphql_single_name, $field_name, [
                                'type' => ['list_of' => 'String'],
                                'description' => $field['desc'],
                                'resolve' => function ($object) use ($object_type, $field) {
                                    $meta = self::_get_meta_value($field, $object, $object_type);

                                    foreach ($meta as &$metaValue) {
                                        $metaValue = self::_convert_wp_internal($metaValue);
                                    }

                                    return $meta;
                                },

                            ]);
                        } else {
                            register_graphql_field($graphql_single_name, $field_name, [
                                'type' => "string",
                                'description' => $field['desc'],
                                'resolve' => function ($object) use ($object_type, $field) {
                                    $meta = self::_get_meta_value($field, $object, $object_type);
                                    $meta = self::_convert_wp_internal($meta);

                                    return $meta;
                                },
                            ]);
                        }
                    }
                }
            }

            return $fields;
        }

        public function add_block_meta_fields($boxes, $fields, $object_type)
        {
            if (!class_exists('\WPGraphQLGutenberg\Schema\Types\BlockTypes')) {
                return;
            }

            foreach ($boxes as $box) {
                $graphql_single_name = 'MetaBox' . \WPGraphQLGutenberg\Schema\Types\BlockTypes::format_block_name($box->id);

                foreach ($box->fields as $field) {
                    if (empty($field['id'])) {
                        continue;
                    }

                    $blockPrefix = 'field';
                    $field_name = self::_graphql_label($blockPrefix . '_' . $field['id']);

                    if (in_array($field['type'], self::$group_fields)) {
                        $group_type_name = ucfirst(self::_graphql_label($graphql_single_name . '_' . $blockPrefix . '_' . $field['id']));
                        $group_fields = [];
                        $image_group_fields = [];

                        foreach ($field['fields'] as $group_sub_field) {
                            if (in_array($group_sub_field['type'], self::$media_fields)) {
                                $image_group_fields[] = $group_sub_field;
                            } else {
                                $group_fields[self::_graphql_label($group_sub_field['id'])] = [
                                    'type' => 'String',
                                    'description' => "Group field - {$group_sub_field['name']}",
                                ];
                            }
                        }

                        register_graphql_object_type($group_type_name, [
                            'description' => "Metabox Block Field Group {$group_type_name} object",
                            'fields' => $group_fields,
                        ]);

                        if ($image_group_fields) {
                            foreach ($image_group_fields as $image_group_field) {
                                register_graphql_connection([
                                    'fromType' => $group_type_name,
                                    'toType' => 'MediaItem',
                                    'fromFieldName' => self::_graphql_label($image_group_field['id']),
                                    'resolve' => function( $item, $args, $context, $info ) use ($object_type, $image_group_field) {
                                        if (isset($item[$image_group_field['id']]) && is_array($item[$image_group_field['id']])) {
                                            if (($image_group_field['clone'] == true || $image_group_field['multiple'] == true)) {
                                                $ids = $item[$image_group_field['id']];
                                            } else {
                                                $ids = [$item[$image_group_field['id']]];
                                            }

                                            $resolver = new \WPGraphQL\Data\Connection\PostObjectConnectionResolver($item, $args, $context, $info, 'attachment');
                                            $resolver->set_query_arg('post__in', $ids);
                                            $resolver->set_query_arg('orderby', 'post__in');

                                            return $resolver->get_connection();
                                        }
                                    },
                                ]);
                            }
                        }

                        if (($field['clone'] == true || $field['multiple'] == true)) {
                            register_graphql_field($graphql_single_name, $field_name, [
                                'type' => ['list_of' => $group_type_name],
                                'description' => $field['desc'],
                                'resolve' => function ($object) use ($object_type, $field) {
                                    $meta = self::_get_block_meta_value($field, $object);
                                    return $meta;
                                },
                            ]);
                        } else {
                            register_graphql_field($graphql_single_name, $field_name, [
                                'type' => $group_type_name,
                                'description' => $field['desc'],
                                'resolve' => function ($object) use ($object_type, $field) {
                                    $meta = self::_get_block_meta_value($field, $object);
                                    return $meta;
                                },
                            ]);
                        }
                    } else if (in_array($field['type'], self::$media_fields)) {
                        // TODO: Does single_image work?
                        register_graphql_connection([
                            'fromType' => $graphql_single_name,
                            'toType' => 'MediaItem',
                            'fromFieldName' => $field_name,
                            'resolve' => function($item, $args, $context, $info ) use ($object_type, $field) {
                                /** @var \WPGraphQLGutenberg\Blocks\Block $item */
                                $meta = self::_get_block_meta_value($field, $item);

                                if (($field['clone'] == true || $field['multiple'] == true)) {
                                    $ids = $meta;
                                } else {
                                    $ids = [$meta];
                                }

                                if (!empty($ids)) {
                                    $resolver = new \WPGraphQL\Data\Connection\PostObjectConnectionResolver($item, $args, $context, $info, 'attachment');
                                    $resolver->set_query_arg('post__in', $ids);
                                    $resolver->set_query_arg('orderby', 'post__in');

                                    return $resolver->get_connection();
                                }
                            },
                        ]);
                    } else if (in_array($field['type'], self::$taxonomy_fields)) {
                        if ($field['clone'] == false && $field['multiple'] == false) {
                            register_graphql_field($graphql_single_name, $field_name, [
                                'type' => 'Term',
                                'description' => $field['desc'],
                                'resolve' => function ($object) use ($object_type, $field) {
                                    $meta = self::_get_block_meta_value($field, $object);

                                    if ($meta) {
                                        $meta = self::_convert_wp_internal(get_term($meta));
                                    }

                                    return $meta;
                                },
                            ]);
                        }

                        if (($field['clone'] == true || $field['multiple'] == true)) {
                            register_graphql_field($graphql_single_name, $field_name, [
                                'type' => ['list_of' => 'Term'],
                                'description' => $field['desc'],
                                'resolve' => function ($object) use ($object_type, $field) {
                                    $meta = self::_get_block_meta_value($field, $object);

                                    foreach ($meta as &$metaValue) {
                                        $metaValue = self::_convert_wp_internal(get_term($metaValue));
                                    }

                                    return $meta;
                                },

                            ]);
                        }
                    } else {
                        if (($field['clone'] == true || $field['multiple'] == true)) {
                            register_graphql_field($graphql_single_name, $field_name, [
                                'type' => ['list_of' => 'String'],
                                'description' => $field['desc'],
                                'resolve' => function ($object) use ($object_type, $field) {
                                    /** @var \WPGraphQLGutenberg\Blocks\Block $object */
                                    $meta = self::_get_block_meta_value($field, $object);

                                    foreach ($meta as &$metaValue) {
                                        $metaValue = self::_convert_wp_internal($metaValue);
                                    }

                                    return $meta;
                                },

                            ]);
                        } else {
                            register_graphql_field($graphql_single_name, $field_name, [
                                'type' => "string",
                                'description' => $field['desc'],
                                'resolve' => function ($object) use ($object_type, $field) {
                                    /** @var \WPGraphQLGutenberg\Blocks\Block $object */
                                    $meta = self::_get_block_meta_value($field, $object);
                                    $meta = self::_convert_wp_internal($meta);

                                    return $meta;
                                },
                            ]);
                        }
                    }
                }
            }
        }

        public function add_settings_meta_fields($boxes, $option_page_id, $options_name, $root_field)
        {
            register_graphql_field('RootQuery', $root_field, [
                'type' => ucfirst(self::_graphql_label($option_page_id) . 'MetaboxSettings'),
                'description' => 'Settings for ' . $options_name,
                'resolve' => function ($root, $args, $context, $info) use ($boxes, $options_name) {
                    $meta = [];

                    foreach ($boxes as $box) {
                        foreach ($box->meta_box['fields'] as $field) {
                            if ($field['clone'] == true || $field['multiple'] == true) {
                                $metaValue = [];
                                $metaMultipleValue = self::_get_meta_value($field, $options_name, 'setting');

                                foreach ($metaMultipleValue as $value) {
                                    $metaValue[] = self::_convert_wp_internal($value);
                                }
                            } else {
                                $metaValue = self::_get_meta_value($field, $options_name, 'setting');
                                $metaValue = self::_convert_wp_internal($metaValue);
                            }

                            $meta[self::_graphql_label($field['id'])] = $metaValue;
                        }
                    }

                    return $meta;
                },

            ]);
        }

        /**
         * @param $field
         * @param \WPGraphQLGutenberg\Blocks\Block $object
         * @return mixed|null
         */
        public static function _get_block_meta_value($field, $object)
        {
            $meta = null;

            if (property_exists($object, 'attributes')) {
                $attributes = $object->attributes;

                if (is_array($attributes) && isset($attributes['data'])) {
                    $data = $attributes['data'];

                    if (isset($data[$field['id']])) {
                        $meta = $data[$field['id']];
                    }
                }
            }

            return $meta;
        }

        /**
         * Get the meta value for a field
         *
         * @param $field
         * @param $object
         * @param $object_type
         * @return mixed|null
         */
        public static function _get_meta_value($field, $object, $object_type)
        {
            $meta = null;

            if ('post' === $object_type || in_array($object_type, get_post_types(), true)) {
                $meta = rwmb_meta(
                    $field['id'],
                    null,
                    ($object instanceof \WPGraphQL\Model\Post) ? $object->ID : intval($object)
                );
            }

            if ('term' === $object_type || in_array($object_type, get_taxonomies(), true)) {
                $meta = rwmb_meta(
                    $field['id'],
                    ['object_type' => 'term'],
                    ($object instanceof \WPGraphQL\Model\Term) ? $object->term_id : intval($object)
                );
            }

            if ('user' === $object_type) {
                $meta = rwmb_meta($field['id'], ['object_type' => 'user'], $object->ID);
                $meta = rwmb_meta(
                    $field['id'],
                    ['object_type' => 'term'],
                    ($object instanceof \WP_User) ? $object->ID : intval($object)
                );
            }

            if ('setting' === $object_type) {
                $meta = rwmb_meta($field['id'], ['object_type' => 'setting'], $object);
            }

            if (!empty($meta) && $field['type'] === 'group') {
                if (($field['clone'] == true || $field['multiple'] == true)) {
                    foreach ($meta as &$groupItem) {
                        $groupItem = self::_get_group_subvalues($groupItem, $field);
                    }
                } else {
                    $meta = self::_get_group_subvalues($meta, $field);
                }
            }
            return $meta;
        }

        public static function _get_group_subvalues($groupMeta, $groupField)
        {
            $newGroupMeta = [];

            foreach ($groupField['fields'] as $field) {
                $originalFieldId = $field['id'];
                $fieldId = self::_graphql_label($originalFieldId);

                if (!empty($groupMeta[$originalFieldId])) {
                    if (in_array($field['type'], self::$media_fields)) {
                        if ($field['type'] === 'video') {
                            if (is_array($groupMeta[$originalFieldId])) {
                                foreach ($groupMeta[$originalFieldId] as $attachment) {
                                    $newGroupMeta[$fieldId][] = RWMB_Video_Field::file_info($attachment, ['size' => 'original']);
                                }
                            } else {
                                $newGroupMeta[$fieldId] = RWMB_Video_Field::file_info($groupMeta[$field['id']], ['size' => 'original']);
                            }
                        } else {
                            if (is_array($groupMeta[$originalFieldId])) {
                                foreach ($groupMeta[$originalFieldId] as $attachment) {
                                    $newGroupMeta[$fieldId][] = RWMB_Image_Field::file_info($attachment, ['size' => 'original']);
                                }
                            } else {
                                $newGroupMeta[$fieldId] = RWMB_Image_Field::file_info($groupMeta[$field['id']], ['size' => 'original']);
                            }
                        }
                    } else {
                        $newGroupMeta[$fieldId] = $groupMeta[$originalFieldId];
                    }
                }
            }

            return $newGroupMeta;
        }

        /**
         * Get post metaboxes
         *
         * @param array $object Post object.
         *
         * @return array
         */
        public function get_post_type_meta_boxes($type)
        {
            $meta_boxes = \rwmb_get_registry('meta_box')->get_by([
                'object_type' => 'post',
            ]);

            foreach ($meta_boxes as $key => $meta_box) {
                if (!in_array($type, $meta_box->post_types, true)) {
                    unset($meta_boxes[$key]);
                }
            }

            return $meta_boxes;
        }

        /**
         * Get block metaboxes
         *
         * @return array
         */
        public function get_block_meta_boxes()
        {
            $meta_boxes = \rwmb_get_registry('meta_box')->get_by([
                'object_type' => 'post',
            ]);

            foreach ($meta_boxes as $key => $meta_box) {
                if ($meta_box->type !== 'block') {
                    unset($meta_boxes[$key]);
                }
            }

            return $meta_boxes;
        }

        /**
         * Get term meta boxes
         *
         * @param array $object Term object.
         *
         * @return array
         */
        public function get_term_meta_boxes()
        {
            if (!class_exists('\MBTM\MetaBox')) {
                return [];
            }

            return \rwmb_get_registry('meta_box')->get_by([
                'object_type' => 'term',
            ]);
        }

        /**
         * Get settings metaboxes
         *
         * @param array $object Post object.
         *
         * @return array
         */
        public function get_settings_meta_boxes()
        {
            if (!class_exists('\MBSP\SettingsPage')) {
                return [];
            }

            return \rwmb_get_registry('meta_box')->get_by([
                'object_type' => 'setting',
            ]);
        }

        /**
         * Get supported supported post types and / or taxonomies.
         *
         * @param string $type 'post' or 'taxonomy'.
         *
         * @return array
         */
        protected function get_types($type)
        {
            switch ($type) {
                case 'post':
                    return get_post_types([], 'objects');
                case 'post_with_blocks':
                    return array_filter(get_post_types([], 'objects'), function ($post_type) {
                        return post_type_supports($post_type->name, 'editor');
                    });
                case 'taxonomy':
                    return get_taxonomies([], 'objects');
            }

            return [];
        }

        /**
         * Utility function for formatting a string to be compatible with GraphQL labels (camelCase with lowercase first letter)
         *
         * @param $input
         *
         * @return mixed|string
         */
        public static function _graphql_label($input)
        {
            $graphql_label = preg_replace('/[-_]/', ' ', $input);
            $graphql_label = ucwords($graphql_label);
            $graphql_label = preg_replace('/ /', '', $graphql_label);
            $graphql_label = lcfirst($graphql_label);

            return $graphql_label;
        }

        /**
         * @param $instance
         * @return string|array
         */
        public static function _convert_wp_internal($instance)
        {
            if (!is_object($instance) && !is_array($instance)) {
                return $instance;
            }

            if (is_object($instance)) {
                // The data is a class, so reflect on it and return the appropriate data
                switch (get_class($instance)) {
                    case 'WP_Term':
                        return [
                            'id' => $instance->term_id,
                            'name' => $instance->name,
                            'slug' => $instance->slug,
                            'description' => $instance->description,
                            'taxonomy' => $instance->taxonomy,
                        ];
                    default:
                        return '';
                }
            } else if (is_array($instance)) {
                // The data is a class, so try and fingerprint it and return the appropriate data
                if (isset($instance['image_meta'])) {
                    // Is an array representing an image
                    return $instance['image_meta'];
                } else if (isset($instance['url'])) {
                    return [
                        'id' => $instance['ID'],
                        'url' => $instance['url'],
                        'title' => $instance['title'],
                        'name' => $instance['name'],
                    ];
                } else {
                    return '';
                }
            }
        }

        public function add_page_template_connection()
        {
            register_graphql_connection(
                [
                    'fromType' => 'RootQuery',
                    'toType' => 'Page',
                    'fromFieldName' => 'pageByTemplate',
                    'connectionTypeName' => 'PageByTemplateConnection',
                    'connectionArgs' => [
                        'template' => [
                            'type' => 'String',
                            'description' => 'The page template to filter by.',
                        ],
                    ],
                    'resolve' => function( $id, $args, $context, $info ) {
                        $resolver   = new PostObjectConnectionResolver( $id, $args, $context, $info, 'page' );
                        $resolver->set_query_arg( 'meta_query', [
                            [
                                'key' => '_wp_page_template',
                                'value' => $args['where']['template'],
                            ],
                        ] );
                        $connection = $resolver->get_connection();
                        return $connection;
                    },
                ]
            );
        }

        public function add_cloudinary_to_mediaitem()
        {
            if (!class_exists('\Cloudinary\Media')) {
                return;
            }

            add_action('graphql_register_types', function () {
                register_graphql_field('MediaItem', 'cloudinaryId', [
                    'type' => 'String',
                    'description' => 'Cloudinary ID for the media item',
                    'resolve' => function ($mediaItem) {
                        $cloudinary = \Cloudinary\get_plugin_instance();
                        return $cloudinary->get_component('media')->get_public_id($mediaItem->ID);
                    },
                ]);
            });
        }

    }
}

add_action('init_graphql_request', function () {
    new MB;
});