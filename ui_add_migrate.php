<?php

namespace Refactor;

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/libs.php';

$srcDir = 'C:\Users\mvorisek\Desktop\dequ\ui_src';
$destDir = 'C:\Users\mvorisek\Desktop\dequ\ui';

// find all classes
$filesToFix = getDirContentsWithRelKeys($srcDir, '~/(?:\.git|vendor/(?!atk4).*/)/|(?<!/|\.php)(?<!/|\.rst)(?<!/|\.md)$~is', 1);

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

$classes = [];
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

                if ($node instanceof Node\Expr\New_ && $node->class instanceof Node\Name && preg_match('~exception(?<!ValidationException)$~is', implode('\\', $node->class->parts))) {
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

                    if (count($node->args) > 0 && $node->args[0]->value instanceof Node\Expr\Array_) {
                        /* @var $exArrArgs Node\Expr\Array_ */
                        $exArrArgs = $node->args[0]->value;
                        $msg = null;
                        $params = [];
                        foreach ($exArrArgs->items as $item) {
                            /* @var $item ArrayItem */
                            if ($item->key === null) {
                                if ($msg !== null) {
                                    throw new \Exception('Duplicate message - check code');
                                }
                                $msg = $item->value;
                            } else {
                                $params[] = [$item->key, $item->value];
                            }
                        }

                        $d = '';
                        if (count($params) > 0) {
                            $d = '(';
                        }
                        $d .= 'new ' . $sub($this->dat, $node->class->getStartFilePos(), $node->class->getEndFilePos());
                        $d .= '(';
                        if ($msg !== null) { // there may be no message
                            $d .= $sub($this->dat, $msg->getStartFilePos(), $msg->getEndFilePos());
                        }
                        foreach (array_slice($node->args, 1) as $k => $arg) {
                            $d .= ($msg !== null || $k > 1 ? ', ' : '') . $sub($this->dat, $arg->getStartFilePos(), $arg->getEndFilePos());
                        }
                        $d .= ')';
                        if (count($params) > 0) {
                            $d .= ')';
                        }
                        foreach ($params as [$k, $v]) {
                            $d .= "\n" . '->addMoreInfo('
                                . $sub($this->dat, $k->getStartFilePos(), $k->getEndFilePos())
                                . ', '
                                . $sub($this->dat, $v->getStartFilePos(), $v->getEndFilePos())
                                . ')';
                        }
                    }

                    // rebuild
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
foreach (array_keys($filesToFix) as $fileRel) {
    $srcFile = $srcDir . '/' . $fileRel;
    $destFile = $destDir . '/' . $fileRel;

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
        $dat = $datOrig;
        if (preg_match('~\.php$~s', $fileRel)) {
            $dumper = new NodeDumper;
            // echo $dumper->dump($astParseFunc($datOrig)) . "\n";

            $dat = $refactorFunc($dat);
        }

        // fix comments, .md/.rst files
        $dat = preg_replace_callback('~(?<=^|\n|//)(?: *\*)?\K[^\n]+new [^\n]*?exception\(\[\(.+?\);~isu', function($matches) use($refactorFunc, $dat) {
            try {
                $phpHeader = '<?php' . "\n" . (preg_match('~namespace(?:::)? +(atk4\\\\ui[^;\n]*?)(?:;|\n)~', $dat, $nsm) ? 'namespace ' . $nsm[1] . ';' . "\n" : '');
                return preg_replace('~^' . preg_quote($phpHeader, '~') . '~isu', '', $refactorFunc($phpHeader . $matches[0]), 1);
            } catch (\Exception $e) {
                return $matches[0]; // do nothing if parse has failed
            }
        }, $dat);

        file_put_contents($destFile, $dat);
    } finally {
        ob_end_flush();
        if ($nameDisplayed) {
            echo "\n\n";
        }
    }
}
