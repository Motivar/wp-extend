## AWM Contend DB Api

This is a library for creating custom content db structure based on posts/postsmeta table

### How to use

we create a "posts" table, with content_id,created,modified,title,status
we create a "posts_meta" table with meta_id,content_id,meta_key,meta_value



### Hooks reference

```php
/**
 * Fires after saving gallery data.
 *
 * @var int     $post_id Post ID.
 * @var WP_Post $post    Post object.
 * @var bool    $update  Whether this is an existing post being updated or not.
 */
do_action( 'gallery_meta_box_save', $post_id, $post, $update );
```

```php
/**
 * Filters supported post types.
 *
 * @var array $post_types List supported post types.
 */
apply_filters( 'gallery_meta_box_post_types', $post_types );
```

