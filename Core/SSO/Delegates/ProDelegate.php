<?php
/**
 * ProDelegate
 * @author edgebal
 */

namespace Minds\Core\SSO\Delegates;

use Minds\Core\Di\Di;
use Minds\Core\Pro\Domain as ProDomain;

class ProDelegate
{
    /** @var ProDomain */
    protected $proDomain;

    /**
     * ProDelegate constructor.
     * @param ProDomain $proDomain
     */
    public function __construct(
        $proDomain = null
    ) {
        $this->proDomain = $proDomain ?: Di::_()->get('Pro\Domain');
    }

    public function isAllowed($domain)
    {
        return $this->proDomain->isRoot($domain)
           || (bool) $this->proDomain->lookup($domain);
    }
}
