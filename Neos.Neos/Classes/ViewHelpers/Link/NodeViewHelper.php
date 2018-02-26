<?php
namespace Neos\Neos\ViewHelpers\Link;

/*
 * This file is part of the Neos.Neos package.
 *
 * (c) Contributors of the Neos Project - www.neos.io
 *
 * This package is Open Source Software. For the full copyright and license
 * information, please view the LICENSE file which was distributed with this
 * source code.
 */

use Neos\ContentRepository\Domain\Projection\Content\ContentSubgraphInterface;
use Neos\ContentRepository\Domain\ValueObject\WorkspaceName;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Mvc\Routing\UriBuilder;
use Neos\FluidAdaptor\Core\ViewHelper\AbstractTagBasedViewHelper;
use Neos\Neos\Domain\Context\Content\ContentQuery;
use Neos\Neos\Domain\Service\NodeShortcutResolver;
use Neos\Neos\Service\LinkingService;
use Neos\FluidAdaptor\Core\ViewHelper\Exception as ViewHelperException;
use Neos\ContentRepository\Domain\Model\NodeInterface;
use Neos\Fusion\ViewHelpers\FusionContextTrait;

/**
 * A view helper for creating links with URIs pointing to nodes.
 *
 * The target node can be provided as string or as a Node object; if not specified
 * at all, the generated URI will refer to the current document node inside the Fusion context.
 *
 * When specifying the ``node`` argument as string, the following conventions apply:
 *
 * *``node`` starts with ``/``:*
 * The given path is an absolute node path and is treated as such.
 * Example: ``/sites/acmecom/home/about/us``
 *
 * *``node`` does not start with ``/``:*
 * The given path is treated as a path relative to the current node.
 * Examples: given that the current node is ``/sites/acmecom/products/``,
 * ``stapler`` results in ``/sites/acmecom/products/stapler``,
 * ``../about`` results in ``/sites/acmecom/about/``,
 * ``./neos/info`` results in ``/sites/acmecom/products/neos/info``.
 *
 * *``node`` starts with a tilde character (``~``):*
 * The given path is treated as a path relative to the current site node.
 * Example: given that the current node is ``/sites/acmecom/products/``,
 * ``~/about/us`` results in ``/sites/acmecom/about/us``,
 * ``~`` results in ``/sites/acmecom``.
 *
 * = Examples =
 *
 * <code title="Defaults">
 * <neos:link.node>some link</neos:link.node>
 * </code>
 * <output>
 * <a href="sites/mysite.com/homepage/about.html">some link</a>
 * (depending on current node, format etc.)
 * </output>
 *
 * <code title="Generating a link with an absolute URI">
 * <neos:link.node absolute="{true}">bookmark this page</neos:link.node>
 * </code>
 * <output>
 * <a href="http://www.example.org/homepage/about.html">bookmark this page</a>
 * (depending on current workspace, current node, format, host etc.)
 * </output>
 *
 * <code title="Target node given as absolute node path">
 * <neos:link.node node="/sites/exampleorg/contact/imprint">Corporate imprint</neos:link.node>
 * </code>
 * <output>
 * <a href="contact/imprint.html">Corporate imprint</a>
 * (depending on current workspace, current node, format etc.)
 * </output>
 *
 * <code title="Target node given as node://-uri">
 * <neos:link.node node="node://30e893c1-caef-0ca5-b53d-e5699bb8e506">Corporate imprint</neos:link.node>
 * </code>
 * <output>
 * <a href="contact/imprint.html">Corporate imprint</a>
 * (depending on current workspace, current node, format etc.)
 * </output>
 *
 * <code title="Target node given as relative node path">
 * <neos:link.node node="~/about/us">About us</neos:link.node>
 * </code>
 * <output>
 * <a href="about/us.html">About us</a>
 * (depending on current workspace, current node, format etc.)
 * </output>
 *
 * <code title="Node label as tag content">
 * <neos:link.node node="/sites/exampleorg/contact/imprint" />
 * </code>
 * <output>
 * <a href="contact/imprint.html">Imprint</a>
 * (depending on current workspace, current node, format etc.)
 * </output>
 *
 * <code title="Dynamic tag content involving the linked node's properties">
 * <neos:link.node node="about-us">see our <span>{linkedNode.label}</span> page</neos:link.node>
 * </code>
 * <output>
 * <a href="about-us.html">see our <span>About Us</span> page</a>
 * (depending on current workspace, current node, format etc.)
 * </output>
 *
 * @api
 */
class NodeViewHelper extends AbstractTagBasedViewHelper
{
    use FusionContextTrait;

    /**
     * @var string
     */
    protected $tagName = 'a';

    /**
     * @Flow\Inject
     * @var LinkingService
     */
    protected $linkingService;

    /**
     * @Flow\Inject
     * @var NodeShortcutResolver
     */
    protected $nodeShortcutResolver;

    /**
     * Initialize arguments
     *
     * @return void
     */
    public function initializeArguments()
    {
        $this->registerUniversalTagAttributes();
        $this->registerTagAttribute('name', 'string', 'Specifies the name of an anchor');
        $this->registerTagAttribute('rel', 'string', 'Specifies the relationship between the current document and the linked document');
        $this->registerTagAttribute('rev', 'string', 'Specifies the relationship between the linked document and the current document');
        $this->registerTagAttribute('target', 'string', 'Specifies where to open the linked document');
    }

    /**
     * Renders the link. Renders the linked node's label if there's no child content.
     *
     * @param mixed $node A node object, a string node path (absolute or relative), a string node://-uri or NULL
     * @param string $format Format to use for the URL, for example "html" or "json"
     * @param boolean $absolute If set, an absolute URI is rendered
     * @param array $arguments Additional arguments to be passed to the UriBuilder (for example pagination parameters)
     * @param string $section The anchor to be added to the URI
     * @param boolean $addQueryString If set, the current query parameters will be kept in the URI
     * @param array $argumentsToBeExcludedFromQueryString arguments to be removed from the URI. Only active if $addQueryString = TRUE
     * @param string $nodeVariableName The variable the node will be assigned to for the rendered child content
     * @param string $baseNodeName The name of the base node inside the Fusion context to use for the ContentContext or resolving relative paths
     * @param boolean $resolveShortcuts INTERNAL Parameter - if FALSE, shortcuts are not redirected to their target. Only needed on rare backend occasions when we want to link to the shortcut itself.
     * @return string The rendered link
     * @throws ViewHelperException
     * @throws \Neos\Flow\Mvc\Routing\Exception\MissingActionNameException
     */
    public function render($node = null, $format = null, $absolute = false, array $arguments = array(), $section = '', $addQueryString = false, array $argumentsToBeExcludedFromQueryString = array(), $baseNodeName = 'documentNode', $nodeVariableName = 'linkedNode', $resolveShortcuts = true)
    {
        $baseNode = null;
        if (!$node instanceof NodeInterface) {
            $baseNode = $this->getContextVariable($baseNodeName);
            if (is_string($node) && substr($node, 0, 7) === 'node://') {
                \Neos\Flow\var_dump($node);
                exit();
                #$node = $this->linkingService->convertUriToObject($node, $baseNode);
            }
        }

        if ($resolveShortcuts) {
            $resolvedNode = $this->nodeShortcutResolver->resolveShortcutTarget($node);
        } else {
            // this case is only relevant in extremely rare occasions in the Neos Backend, when we want to generate
            // a link towards the *shortcut itself*, and not to its target.
            $resolvedNode = $node;
        }

        /** @var ContentQuery $contentQuery */
        $contentQuery = $this->getContextVariable('contentQuery');
        $contentQuery = $contentQuery->withNodeAggregateIdentifier($resolvedNode->aggregateIdentifier);

        $uriBuilder = new UriBuilder();
        $uriBuilder->setRequest($this->controllerContext->getRequest());
        $uriBuilder->setFormat($format)
            ->setCreateAbsoluteUri($absolute)
            ->setArguments($arguments)
            ->setSection($section)
            ->setAddQueryString($addQueryString)
            ->setArgumentsToBeExcludedFromQueryString($argumentsToBeExcludedFromQueryString);

        $uri = $uriBuilder->uriFor(
            'show',
            [
                'node' => $contentQuery
            ],
            'Frontend\Node',
            'Neos.Neos'
        );
        $this->tag->addAttribute('href', $uri);

        $this->templateVariableContainer->add($nodeVariableName, $resolvedNode);
        $content = $this->renderChildren();
        $this->templateVariableContainer->remove($nodeVariableName);

        if ($content === null && $resolvedNode !== null) {
            $content = $resolvedNode->getLabel();
        }

        $this->tag->setContent($content);
        $this->tag->forceClosingTag(true);
        return $this->tag->render();
    }
}
