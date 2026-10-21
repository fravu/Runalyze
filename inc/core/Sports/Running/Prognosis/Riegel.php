<?php

namespace Runalyze\Sports\Running\Prognosis;

/**
 * Prognosis by Peter Riegel
 *
 * T2 = T1 x (D2 / D1)^1.06, the exponent describes the fatigue over time/distance.
 * @see Peter Riegel, "Athletic Records and Human Endurance", American Scientist (1981)
 */
class Riegel implements PrognosisInterface
{
    /** @var float default exponent */
    const EXPONENT = 1.06;

    /** @var float [km] */
    protected $ReferenceDistance = 0.0;

    /** @var float [s] */
    protected $ReferenceTime = 0.0;

    /** @var float */
    protected $Exponent;

    /**
     * @param float|int $referenceTime [s]
     * @param float $referenceDistance [km]
     * @param float $exponent
     */
    public function __construct($referenceTime = 0.0, $referenceDistance = 0.0, $exponent = self::EXPONENT)
    {
        $this->Exponent = (float)$exponent;
        $this->setReferenceResult($referenceDistance, $referenceTime);
    }

    public function areValuesValid()
    {
        return 0.0 < $this->ReferenceDistance && 0.0 < $this->ReferenceTime;
    }

    /**
     * @param float $distance [km]
     * @param int|float $timeInSeconds [s]
     * @return $this
     */
    public function setReferenceResult($distance, $timeInSeconds)
    {
        $this->ReferenceDistance = (float)$distance;
        $this->ReferenceTime = (float)$timeInSeconds;

        return $this;
    }

    public function getSeconds($distance)
    {
        if ($distance <= 0.0 || !$this->areValuesValid()) {
            return null;
        }

        return $this->ReferenceTime * pow($distance / $this->ReferenceDistance, $this->Exponent);
    }
}
