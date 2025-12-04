<?php

namespace App\Catalog\Form;

use App\Catalog\Entity\Product;
use App\Catalog\Entity\Category;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\MoneyType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class ProductType extends AbstractType
{
  public function buildForm(FormBuilderInterface $builder, array $options): void
  {
    $builder
      ->add('name', TextType::class, [
        'label' => 'Nazwa produktu',
      ])
      ->add('price', MoneyType::class, [
        'label' => 'Cena',
        'currency' => 'PLN',
      ])
      ->add('description', TextareaType::class, [
        'label' => 'Opis',
        'required' => false,
      ])
      ->add('category', EntityType::class, [
        'label' => 'Kategoria',
        'class' => Category::class,
        'choice_label' => 'name',
        'placeholder' => 'Wybierz kategorię',
      ]);
  }

  public function configureOptions(OptionsResolver $resolver): void
  {
    $resolver->setDefaults([
      'data_class' => Product::class,
    ]);
  }
}