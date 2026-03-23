<?php
namespace setasign\Fpdi;
/* Placeholder mínimo para evitar errores mientras subes FPDI real.
   Debes reemplazar este archivo por la librería FPDI oficial (edición gratuita)
   desde https://www.setasign.com/products/fpdi/download/ y sus dependencias.
*/
class Fpdi {
  public function __call($n,$a){ throw new \Exception('FPDI no instalado. Sube la librería real a /lib.'); }
  public function SetAutoPageBreak(){ throw new \Exception('FPDI no instalado.'); }
  public function AddPage(){ throw new \Exception('FPDI no instalado.'); }
  public function setSourceFile(){ throw new \Exception('FPDI no instalado.'); }
  public function importPage(){ throw new \Exception('FPDI no instalado.'); }
  public function getTemplateSize(){ throw new \Exception('FPDI no instalado.'); }
  public function useTemplate(){ throw new \Exception('FPDI no instalado.'); }
}
