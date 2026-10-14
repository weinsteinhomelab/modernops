<?php
/** Industry / vertical choices for businesses, grouped for the dropdown. */

function vertical_groups(): array {
    return [
        'Food & Drink' => [
            'Restaurant', 'Café / Coffee Shop', 'Bakery', 'Bar / Brewery / Winery', 'Food Truck',
            'Catering', 'Pizza / Takeout', 'Deli / Market', 'Ice Cream / Dessert',
        ],
        'Home Services & Trades' => [
            'General Contractor', 'Roofing', 'Plumbing', 'Electrical', 'HVAC', 'Landscaping / Lawn Care',
            'Painting', 'Flooring', 'Remodeling', 'Handyman', 'Cleaning / Janitorial', 'Pest Control',
            'Pool / Spa Service', 'Moving / Storage', 'Junk Removal', 'Locksmith', 'Garage Doors',
            'Fencing / Decks', 'Paving / Masonry', 'Snow Removal', 'Tree Service', 'Home Inspection',
            'Security / Alarm Systems', 'Solar',
        ],
        'Automotive' => [
            'Auto Repair', 'Auto Body / Collision', 'Auto Detailing', 'Car Dealership', 'Towing',
            'Tires / Oil Change', 'Car Wash',
        ],
        'Health & Wellness' => [
            'Medical Practice', 'Dental', 'Chiropractic', 'Physical Therapy', 'Optometry',
            'Mental Health / Counseling', 'Med Spa', 'Massage', 'Pharmacy', 'Home Health Care',
            'Veterinary', 'Fitness / Gym', 'Yoga / Pilates', 'Martial Arts', 'Nutrition / Dietitian',
        ],
        'Beauty & Personal Care' => [
            'Hair Salon', 'Barber', 'Nail Salon', 'Day Spa', 'Tattoo / Piercing', 'Esthetics / Skincare',
            'Lashes / Brows',
        ],
        'Pets' => [
            'Pet Grooming', 'Dog Daycare / Boarding', 'Dog Training', 'Pet Sitting / Walking', 'Pet Store',
        ],
        'Professional Services' => [
            'Law Firm', 'Accounting / Bookkeeping', 'Tax Preparation', 'Insurance Agency',
            'Financial Advisor', 'Real Estate Agent / Brokerage', 'Property Management', 'Mortgage',
            'Consulting', 'Marketing / Advertising Agency', 'IT Services / MSP', 'Architecture',
            'Engineering', 'Staffing / Recruiting', 'Notary', 'Printing / Signs',
        ],
        'Retail' => [
            'Boutique / Clothing', 'Gift Shop', 'Jewelry', 'Florist', 'Furniture / Home Decor',
            'Hardware Store', 'Liquor Store', 'Smoke / Vape Shop', 'Sporting Goods', 'Bike Shop',
            'Thrift / Consignment', 'E-commerce / Online Store',
        ],
        'Events & Creative' => [
            'Photography', 'Videography', 'Event Planning', 'Wedding Services', 'DJ / Entertainment',
            'Venue / Event Space', 'Party Rentals', 'Graphic Design', 'Music Lessons / Studio', 'Art Studio / Gallery',
        ],
        'Education & Childcare' => [
            'Daycare / Preschool', 'Tutoring', 'Private School', 'Driving School', 'Dance Studio',
            'Camps / After-school',
        ],
        'Community & Nonprofit' => [
            'Nonprofit / Charity', 'Church / Religious', 'Club / Association', 'Government / Municipal',
        ],
        'Hospitality & Travel' => [
            'Hotel / Motel', 'Bed & Breakfast', 'Vacation Rental', 'Travel Agency', 'Tours / Recreation',
        ],
        'Other Industries' => [
            'Manufacturing', 'Construction (Commercial)', 'Transportation / Logistics', 'Trucking',
            'Agriculture / Farm', 'Wholesale / Distribution', 'Funeral Home', 'Laundromat / Dry Cleaning',
            'Self Storage', 'Senior Living', 'Other',
        ],
    ];
}

function vertical_all(): array {
    return array_merge(...array_values(vertical_groups()));
}

/** <select> for the vertical, keeping any older free-text value that isn't in the list. */
function vertical_select(string $name, string $current, string $placeholder = '— Select industry —', string $attrs = ''): string {
    $known = in_array($current, vertical_all(), true);
    $h = '<select name="' . e($name) . '"' . ($attrs ? ' ' . $attrs : '') . '>';
    $h .= '<option value="">' . e($placeholder) . '</option>';
    if ($current !== '' && !$known) {
        $h .= '<option value="' . e($current) . '" selected>' . e($current) . ' (current)</option>';
    }
    foreach (vertical_groups() as $group => $items) {
        $h .= '<optgroup label="' . e($group) . '">';
        foreach ($items as $v) {
            $h .= '<option value="' . e($v) . '"' . ($v === $current ? ' selected' : '') . '>' . e($v) . '</option>';
        }
        $h .= '</optgroup>';
    }
    return $h . '</select>';
}
