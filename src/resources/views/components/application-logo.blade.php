{{-- Our mark: public/logo.png (a balance weighing gold against goods). Size it with h-*/w-* classes; the favicon
     files in public/ are cut from the same art (master: resources/branding/logo-master.jpg). --}}
<img src="{{ asset('logo.png') }}" alt="" width="192" height="192" {{ $attributes }}>
