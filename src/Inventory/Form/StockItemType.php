<?php

namespace App\Inventory\Form;

use App\Inventory\Entity\StockItem;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class StockItemType extends AbstractType
{
  public function buildForm(FormBuilderInterface $builder, array $options): void
  {
    $builder
      ->add('quantity', IntegerType::class, [
        'label' => 'Ilość',
        'attr' => ['min' => 0],
      ]);
  }

  public function configureOptions(OptionsResolver $resolver): void
  {
    $resolver->setDefaults([
      'data_class' => StockItem::class,
    ]);
  }
}
