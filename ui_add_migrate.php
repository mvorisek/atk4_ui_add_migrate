<?php

namespace Refactor;

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/libs.php';

$srcDir = 'C:\sync\wlocal\kelly-atk\mvorisek-php-atk\vendor\atk4\data';
$destDir = 'C:\Users\mvorisek\Desktop\dequ\atk4_ui\data';

// find all classes
$phpFiles = getDirContentsWithRelKeys($srcDir, '~/(?:\.git|(?<!mvorisek-php-atk/)vendor)/|(?<!/|\.php)$~is', 1);
$classesAll = array_filter(array_map(function($f) { return discoverClasses($f); }, $phpFiles), function($v) { return count($v) > 0; });
$classes = [];
foreach ($classesAll as $k => $cls) {
    if (preg_match('~^src[/\\\\]~', $k)) {
        foreach ($cls as $cl) {
            $classes[] = $cl;
        }
    }
}

// find non-unique relative class names
// ---> not needed to solve, all "function add(" accept "\atk4\ui\" relative or absolute class names only!
//$clsByRelCl = [];
//foreach ($classes as $cl) {
//    $clsByRelCl[preg_replace('~.+\\\\~', '', $cl)][] = $cl;
//}
//$clsByRelClNonUnique = array_filter($clsByRelCl, function($v) { return count($v) > 1; });
//print_r($clsByRelClNonUnique);
//echo implode('|', array_keys($clsByRelClNonUnique));

use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;
use PhpParser\Error;
use PhpParser\NodeDumper;
use PhpParser\Lexer;
use PhpParser\Parser;

/**
 * @return Node\Stmt[]
 */
$astParseFunc = function(string $dat): array {
    $lexer = new Lexer\Emulative([
        'usedAttributes' => [
            'comments',
            'startLine', 'endLine',
            'startFilePos', 'endFilePos',
            'startTokenPos', 'endTokenPos',
        ],
    ]);
    $parser = new Parser\Php7($lexer);
    try {
        return $parser->parse($dat);
    } catch (Error $e) {
        throw new \Exception("Parse error: {$e->getMessage()}", 0, $e);
    }
};

$refactorFunc = function(string $dat) use($astParseFunc, $classes): string {
    $runCount = 0;
    do {
        $traverser = new NodeTraverser();
        $visitor = new class($dat, $classes) extends NodeVisitorAbstract {
            public $datOrig;
            public $dat;
            public $classes;
            public $ns;
            public $uses = [];

            private $stack;

            public function __construct($dat, array $classes) {
                $this->datOrig = $dat;
                $this->dat = $dat;

                $this->classes = [];
                foreach ($classes as $cl) {
                    $this->classes[mb_strtolower($cl)] = $cl;
                }
            }

            public function beforeTraverse(array $nodes) {
                $this->stack = [];
            }

            public function enterNode(Node $node) {
                if (!empty($this->stack)) {
                    $node->setAttribute('parent', $this->stack[count($this->stack)-1]);
                }
                $this->stack[] = $node;

                if ($node instanceof Node\Stmt\Namespace_) {
                    $this->ns = implode('\\', $node->name->parts);
                } elseif ($node instanceof Node\Stmt\Use_) {
                    foreach ($node->uses as $use) {
                        $v = $this->normalizeClassName('\\' . implode('\\', $use->name->parts), '\\');
                        $this->uses[$use->alias !== null ? $use->alias->name : end($use->name->parts)] = $v;
                    }
                } elseif ($node instanceof Node\Expr\MethodCall) {
                    if ($this->datOrig !== $this->dat) { // always reparse before next modification
                        return;
                    }

                    $sub = function($str, $startPos, $endPos) {
                        return substr($str, $startPos, $endPos - $startPos + 1);
                    };
                    $replaceInside = function($d, $dOffset, $startPos, $endPos, callable $replFx) {
                        $parts = [
                            substr($d, 0, $startPos - $dOffset),
                            substr($d, $startPos - $dOffset, $endPos - $startPos + 1),
                            substr($d, $endPos - $dOffset + 1)
                        ];
                        return $replFx($parts);
                    };

                    $dOffset = $node->getStartFilePos();
                    $dOrig = $sub($this->dat, $dOffset, $node->getEndFilePos());
                    $d = $dOrig;

                    if ($node->name->name === 'add') { // there is no other add() method than View::add(), no need to further check
                        return; // @TODO ignore add() migr for this branch
                        $addParentStr = $sub($this->dat, $node->getStartFilePos(), $node->var->getEndFilePos());

                        $cl = null;
                        if (count($node->args) > 0) {
                            $argSeed = $node->args[0]->value;

                            $dExclAddOffset = $argSeed->getStartFilePos();
                            $dExclAdd = substr($d, $dExclAddOffset - $dOffset);
                            if (count($node->args) > 1) { // convert $region to $add_args, i.e. wrap 2nd arg in array
                                $argRegion = $node->args[1]->value;
                                $dExclAdd = $replaceInside($dExclAdd, $dExclAddOffset, $argRegion->getStartFilePos(), $argRegion->getEndFilePos(), function($parts) {
                                    return $parts[0] . '[' . $parts[1] . ']' . $parts[2];
                                });
                            }

                            $getClFunc = function($n) {
                                if ($n instanceof Node\Expr\ClassConstFetch) {
                                    return $this->buildClassName(implode('\\', $n->class->parts), $this->ns);
                                } else {
                                    return $this->buildClassName($n->value, $this->ns);
                                }
                            };
                            if ($argSeed instanceof Node\Scalar\String_) {
                                $cl = $getClFunc($argSeed);
                                $dExclAdd = '[], ' . $replaceInside($dExclAdd, $dExclAddOffset, $argSeed->getStartFilePos(), $argSeed->getEndFilePos(), function($parts) {
                                    return $parts[0] . preg_replace('~^\s*,\s*~', '', $parts[2]);
                                });

                                if (count($node->args) === 1) {
                                    $dExclAdd = '-';
                                }
                            } elseif ($argSeed instanceof Node\Expr\Array_ && count($argSeed->items) > 0) {
                                $i0 = $argSeed->items[0];
                                $i0Val = $argSeed->items[0]->value;
                                if ($i0->key == 0 && ($i0Val instanceof Node\Scalar\String_ || $i0Val instanceof Node\Expr\ClassConstFetch)) {
                                    $cl = $getClFunc($i0Val);
                                    $dExclAdd = $replaceInside($dExclAdd, $dExclAddOffset, $i0->getStartFilePos(), $i0->getEndFilePos(), function($parts) {
                                        return $parts[0] . preg_replace('~^\s*,\s*~', '', $parts[2]);
                                    });

                                    if (count($argSeed->items) === 1 && count($node->args) === 1) {
                                        $dExclAdd = '-';
                                    }
                                }
                            } elseif ($argSeed instanceof Node\Expr\New_) {
                                $cl = $this->buildClassName(implode('\\', $argSeed->class->parts), $this->ns);
                                $dExclAdd = $replaceInside($dExclAdd, $dExclAddOffset, $argSeed->getStartFilePos(), $argSeed->getEndFilePos(), function($parts)
                                        use(&$cl, $argSeed, $node, $replaceInside, $dExclAdd, $dExclAddOffset) {
                                    if (count($argSeed->args) === 0) {
                                        if (count($node->args) === 1) {
                                            return '-';
                                        } else {
                                            $arrSeedStr = '[]';
                                        }
                                    } else { // this can break code, but as we are refactoring only add of \atk4\ui\* classes we expect some construtors behaviour
                                        $arrSeedStr = $replaceInside($dExclAdd, $dExclAddOffset, reset($argSeed->args)->getStartFilePos(), end($argSeed->args)->getEndFilePos(), function($parts) {
                                            return $parts[1];
                                        });
                                        if (!(count($argSeed->args) === 1 && $argSeed->args[0]->value instanceof Node\Expr\Array_)) {
                                            $arrSeedStr = '[' . $arrSeedStr . ']';
                                            // $cl = null; return; // debug, do nothing
                                        }
                                    }

                                    return $parts[0] . $arrSeedStr . (count($node->args) === 1 ? '' : ', ') . preg_replace('~^\s*,\s*~', '', $parts[2]);
                                });
                            }
                        }

                        if ($cl !== null) {
                            $d = $cl . '::addTo'
                                    . $sub($this->dat, $node->name->getEndFilePos() + 1, $argSeed->getStartFilePos() - 1)
                                    . $addParentStr . ($dExclAdd !== '-' ? ', ' . $dExclAdd : ')');
                        }
                    } elseif ($node->name->name === 'onHook' || $node->name->name === 'addHook') {
                        if (count($node->args) >= 3) {
                            $argHookArgs = $node->args[2]->value;
                            if ($argHookArgs instanceof Node\Expr\ConstFetch) {
                                if ($argHookArgs->name->parts[0] === 'null') {
                                    $d = $replaceInside($d, $dOffset, $argHookArgs->getStartFilePos(), $argHookArgs->getEndFilePos(), function($parts) {
                                        return $parts[0] . '[]' . $parts[2];
                                    });
                                }
                            }
                        }
                    }

                    if ($d !== $dOrig) {
                        var_dump($dOrig);
                        var_dump($d);
                        echo "\n";

                        $this->dat = substr($this->dat, 0, $node->getStartFilePos())
                                . $d . substr($this->dat, $node->getEndFilePos() + 1);
                    }
                }
            }

            public function leaveNode(Node $node) {
                array_pop($this->stack);
            }

            protected function normalizeClassName(string $name, $prefix = '\\') {
                require_once __DIR__ . '/../atk4_ui/atk4_ui/vendor/atk4/core/src/FactoryTrait.php';
                $cl = new class() { use \atk4\core\FactoryTrait; };
                return trim($cl->normalizeClassName($name, $prefix), '\\');
            }

            protected function buildClassName(string $name, string $targetNamespace = null, $returnAbs = false): string {
                if ($name === 'self' || $name === 'static') {
                    return $name;
                }

                foreach ($this->uses as $k => $v) {
                    if (mb_strtolower($name) === mb_strtolower($k)) {
                        $name = '\\' . $v;
                        break;
                    }
                }

                $fqCl = $this->normalizeClassName($name, '\atk4\ui');
                if (!isset($this->classes[mb_strtolower($fqCl)])) {
                    $fqCl = $this->normalizeClassName($name, $targetNamespace);
                    if (!isset($this->classes[mb_strtolower($fqCl)])) {
                        throw new \Exception('"' . $name . '" can not be resolved to an UI class');
                    }
                }
                if ($fqCl !== $this->classes[mb_strtolower($fqCl)]) {
                    throw new \Exception('"' . $name . '" has bad case');
                }
                $fqNs = $this->normalizeClassName('\\' . $targetNamespace, '\\');

                foreach ($this->uses as $k => $v) {
                    if (mb_strtolower($fqCl) === mb_strtolower($v)) {
                        return $k;
                    }
                }

                $relCl = preg_replace('~^' . preg_quote($fqNs, '~') . '\\\\~isu', '', $fqCl);
                return $returnAbs || $relCl === $fqCl ? '\\' . $fqCl : $relCl;
            }
        };
        $traverser->addVisitor($visitor);

        $ast = $astParseFunc($dat);
        $traverser->traverse($ast);

        if (++$runCount >= 250) {
            throw new \Exception('Refactor did not stabilized in ' . $runCount . ' runs');
        }

        $oldDat = $dat;
        $dat = $visitor->dat;
    } while($dat !== $oldDat);

    return $dat;
};

$cc = 0;
foreach (array_keys($phpFiles) as $phpFileRel) {
    $srcFile = $srcDir . '/' . $phpFileRel;
    $destFile = $destDir . '/' . $phpFileRel;

    $datOrig = file_get_contents($srcFile);
    $nameDisplayed = false;
    ob_start(function($v) use(&$nameDisplayed, $srcFile) {
        if (strlen($v) > 0) {
            if (!$nameDisplayed) {
                $v = '--> ' . $srcFile . "\n" . $v;
            }
            $nameDisplayed = true;
        }
        return $v;
    });
    try {
        $dumper = new NodeDumper;
        // echo $dumper->dump($astParseFunc($datOrig)) . "\n";

        $dat = $refactorFunc($datOrig);

        file_put_contents($destFile, $dat);
    } finally {
        ob_end_flush();
        if ($nameDisplayed) {
            echo "\n\n";
        }
    }
}
