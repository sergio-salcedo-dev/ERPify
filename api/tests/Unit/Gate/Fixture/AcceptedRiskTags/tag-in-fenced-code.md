# Fixture: a tag inside a fenced code block must be ignored

Ordinary prose paragraph with no tag of its own, present only so the file has real content around the
fenced example below.

```php
/**
 * Example only -- this must never become a live dependency.
 * @accepted-risk #504
 */
```

More ordinary prose after the fence, also with no tag.
