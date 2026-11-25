# CONFIDENTECH / RTU Equipment Hub (Render + Supabase)

PHP app in a Docker container on Render, with Supabase for the database (Postgres) and equipment photos (Storage).
## Languages Used
1. PHP
2. CSS
3. JavaScript
4. HTML
5. DockerFile

## Features
1. NLP Chatbot that can assist both the students and PEMU Admin in RTU-PASIG. Can automatically ask questions for you.
2. Damage reports for admin to be used as a monitoring tool for the equipments para ma ensure ang quality ng mga equipments.
3. Automated receipts everytime a student borrows an equipment. Pwede magamit para makita kung sino ang huling humiram ng equipment.
4. Online and can be used by students everywhere, Kailangan lang connected sa internet.

## Version 1.1.2
1. **Greetings for users and admin**
   - Time-based greeting: Good morning (12:00 AM to 11:59 AM), Good afternoon (12:00 PM to 5:59 PM), Good evening (6:00 PM to 11:59 PM)
   - Live date and clock that updates every second
   - Admin banner greets "Ma'am Gunda"; the student banner uses the student's first name
   - Gold (admin) and blue (student) banner with readable white text, and the robot mascot on the side
2. **Browser tab icon**
   - The RTU seal now shows as the favicon
3. **Dark mode fixes**
   - Moon / sun button in the top bar; the choice is remembered in the browser
   - Dropdown lists (for example the Category field) and dialog buttons ("Stay logged in") are now readable
   - Dialog titles are gold on the admin side, and dialog text is dark and readable in light mode
   - The login page always stays in light mode
4. **Password eye**
   - Eye icon on every password field (login, register, and dialogs) to show or hide the password
   - The lock icon stays visible when the browser autofills the password
   - No more doubled icon when the password is revealed
5. **Moving background on the login page**
   - The campus photo slowly zooms in and out, and the card enters with a soft animation
6. **Professional page titles**
   - Serif page titles in the top banner (Dashboard Overview, Equipment Inventory, My Borrow Requests, and so on)
   - Sidebar labels match the page titles

## Version 1.1.3
1. **Responsive mobile sidebar**
   - Moved the sidebar navigation to the bottom of the screen on mobile devices to maximize the main content area
   - Icon-only bar with the active tab highlighted; the chatbot and notifications stay above the bar
   - Desktop is unchanged (slim sidebar that opens on hover)
2. **Consistent fonts**
   - Poppins is now bundled in `assets/fonts.css` (one small file, works offline, no Google request)
   - Serif titles use the same style in the sidebar and in the page banner
3. **Movable chatbot**
   - Drag the robot orb anywhere on the screen (mouse or touch); it remembers its position
   - A simple tap still opens or closes the chat, and the chat window opens in the direction that fits on screen
4. **Chatbot polish**
   - New message bubbles, clearer suggested questions (swipeable row on phones), and smoother animations
5. **Mobile top bar**
   - Compact top bar so the page title is no longer cut off
   - "Password resets" shows as a key icon on phones, and the user name text is hidden
6. **Admin sidebar icon**
   - "Account & Backup" now has a database icon instead of the sun-like gear (which looked like the dark mode button)

## Version 1.1.4
1. **Chatbot**
   - Sent button is now fixed (Centered)
   - Icon is replaced with blue orb
2. **Slight UI color grading fix**
   - Color gradient for both student and admin dashboard on pc and android is fixed.
   - Dashboard RTU logo is enhanced
   - Login page blocks is centered (Fixed)

## Version 1.1.5
1. **Receipt**
   - Receipt print function is removed.
   - Added Data Privacy consent pop up when a student registers.
   - Added extra column for return time and date on admin dashboard.

## Made By: ICS-01-502P (GROUP 11)
1. ESCARA, FRANCIS JUSTINE
2. GONZAGA, KAZANDRA ZHANTEL
3. HEBRES, CHRISTIAN

RIZAL TECHNOLOGICAL UNIVERSITY - PASIG CAMPUS   (c) 2026