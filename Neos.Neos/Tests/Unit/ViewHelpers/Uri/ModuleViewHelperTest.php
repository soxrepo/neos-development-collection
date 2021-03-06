<?php
namespace Neos\Neos\Tests\Unit\ViewHelpers\Uri;

/*
 * This file is part of the Neos.Neos package.
 *
 * (c) Contributors of the Neos Project - www.neos.io
 *
 * This package is Open Source Software. For the full copyright and license
 * information, please view the LICENSE file which was distributed with this
 * source code.
 */

use Neos\Flow\Mvc\Routing\UriBuilder;
use Neos\Flow\Tests\UnitTestCase;
use Neos\Neos\ViewHelpers\Uri\ModuleViewHelper;

/**
 */
class ModuleViewHelperTest extends UnitTestCase
{
    /**
     * @var \PHPUnit_Framework_MockObject_MockObject|ModuleViewHelper
     */
    protected $viewHelper;

    /**
     * @var \PHPUnit_Framework_MockObject_MockObject|UriBuilder
     */
    protected $uriBuilder;

    /**
     */
    public function setUp(): void
    {
        parent::setUp();
        $this->viewHelper = $this->getMockBuilder(ModuleViewHelper::class)->setMethods(['setMainRequestToUriBuilder'])->getMock();
        $this->uriBuilder = $this->createMock(UriBuilder::class);
        $this->inject($this->viewHelper, 'uriBuilder', $this->uriBuilder);
    }

    /**
     * @test
     */
    public function callingRenderAssignsVariablesCorrectlyToUriBuilder()
    {
        $this->uriBuilder->expects($this->once())->method('setSection')->with('section')->will($this->returnSelf());
        $this->uriBuilder->expects($this->once())->method('setArguments')->with(['additionalParams'])->will($this->returnSelf());
        $this->uriBuilder->expects($this->once())->method('setArgumentsToBeExcludedFromQueryString')->with(['argumentsToBeExcludedFromQueryString'])->will($this->returnSelf());
        $this->uriBuilder->expects($this->once())->method('setFormat')->with('format')->will($this->returnSelf());

        $expectedModifiedArguments = [
            'module' => 'the/path',
            'moduleArguments' => ['arguments', '@action' => 'action']
        ];

        $this->uriBuilder->expects($this->once())->method('uriFor')->with('index', $expectedModifiedArguments);

        // fallback for the method chaining of the URI builder
        $this->uriBuilder->expects($this->any())->method($this->anything())->will($this->returnValue($this->uriBuilder));

        $this->viewHelper->render(
            'the/path',
            'action',
            ['arguments'],
            'section',
            'format',
            ['additionalParams'],
            true, // `addQueryString`,
            ['argumentsToBeExcludedFromQueryString']
        );
    }
}
