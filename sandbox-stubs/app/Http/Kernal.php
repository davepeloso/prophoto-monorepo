protected $middlewareGroups = [
'web' => [
\ProPhoto\Access\Http\Middleware\ResolveTenant::class,
// ...
],
];
