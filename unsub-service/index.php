<?php
/**
 * Root front controller for locked Hostinger Document Root = mail/
 * Keeps public URLs at /, /api/..., /u/... without /public/ in the path.
 * Does not duplicate application logic.
 */
require __DIR__ . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'index.php';
