<?php

namespace Dedoc\Scramble\Infer\Services;

use Illuminate\Support\Str;
use PhpParser\NameContext;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt\Use_;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;

class FileNameResolver
{
    public static $nameContextCache = [];

    public function __construct(public readonly NameContext $nameContext) {}

    public static function createForFile(string $fileName): self
    {
        if (isset(static::$nameContextCache[$fileName])) {
            return new self(static::$nameContextCache[$fileName]);
        }

        $content = ($path = $fileName)
            ? file_get_contents($path)
            : '<? class Foo {}'; // @todo add extends, implements, etc. Maybe make name context manually.

        preg_match(
            '/(class|enum|interface|trait)\s+?(.*?)\s+?{/m',
            $content,
            $matches,
        );

        $firstMatchedClassLikeString = $matches[0] ?? '';

        $code = Str::before($content, $firstMatchedClassLikeString);

        // Removes all comments.
        $code = preg_replace('/\/\*(?:[^*]|\*+[^*\/])*\*+\/|(?<![:\'"])\/\/.*|(?<![:\'"])#.*/', '', $code);

        $re = '/^(namespace|use) ([.\s\S]*?);/m';
        preg_match_all($re, $code, $matches);

        $code = "<?php\n".implode("\n", $matches[0]);

        $nodes = FileParser::getInstance()->parseContent($code)->getStatements();

        $traverser = new NodeTraverser;
        $traverser->addVisitor($nameResolver = new NameResolver);
        $traverser->traverse($nodes);

        return new self(static::$nameContextCache[$fileName] = $nameResolver->getNameContext());
    }

    public function __invoke(string $shortName): string
    {
        if (str_starts_with($shortName, '\\')) {
            // PHPDoc FQN like `\App\Services\Foo\Bar` is already resolved.
            $name = ltrim($shortName, '\\');
        } else {
            $resolved = $this->nameContext->getResolvedName(new Name($shortName), Use_::TYPE_NORMAL);
            $name = $resolved?->toString() ?? $shortName;
        }

        $classLikeExists = class_exists($name)
            || interface_exists($name)
            || trait_exists($name)
            || enum_exists($name);

        // By definition, the returned class name here is FQN, so like *::class or get_class(*)
        // invoking name resolver returns the class name without leading slash.
        return ltrim($classLikeExists ? $name : $shortName, '\\');
    }
}
