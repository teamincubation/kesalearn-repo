-- SQL Script to Update User Names Based on Email Mappings
-- Generated from name-email CSV file
-- Execute this script to update user names in the database

START TRANSACTION;

-- Update user names where email matches the provided list
UPDATE users SET name = CASE email
    WHEN '13farha@gmail.com' THEN 'Farha K V'
    WHEN '13mariageorge6@gmail.com' THEN 'Maria George'
    WHEN '24ppya101@stellamariscollege.edu.in' THEN 'Jeslyn Mariam Punnoose'
    WHEN '7atsevenashiquemohammad@gmail.com' THEN 'Ashiq Mohammad A'
    WHEN 'aadhiaddz@gmail.com' THEN 'Adil Hakkim'
    WHEN 'aadithyanpradeep2006@gmail.com' THEN 'Aadithyan Pradeep'
    WHEN 'aaishamdy@gmail.com' THEN 'Aaisha Nihala T'
    WHEN 'aarathii.p.s@gmail.com' THEN 'P S Arathi'
    WHEN 'abbadabi13@gmail.com' THEN 'Abbad Mohamed'
    WHEN 'abbiud55@gmail.com' THEN 'Mohammed Rahees'
    WHEN 'abdufrkzz@gmail.com' THEN 'Abdulla Hazeen Sait'
    WHEN 'abdulraoofkk313@gmail.com' THEN 'Abdul Raoof K K'
    WHEN 'abduraheem1930@gmail.com' THEN 'Abduraheem'
    WHEN 'abhirambhaskar041@gmail.com' THEN 'Abhiram P'
    WHEN 'abhirambhaskarp@gmail.com' THEN 'Abhiram P'
    WHEN 'abhiramiammus2001@gmail.Com' THEN 'Abhirami P'
    WHEN 'abhiramii.es@gmail.com' THEN 'Abhirami E S'
    WHEN 'abhiramm077@gmail.com' THEN 'Abhiram M'
    WHEN 'abhisreesprasad@gmail.com' THEN 'Abhisree S Prasad'
    WHEN 'abidakp21@gmail.com' THEN 'Abida K P'
    WHEN 'Abijithnarayanan25@gmail.com' THEN 'Abijith P'
    WHEN 'abilfidhanfidz@gmail.com' THEN 'Abil Fidhan K P'
    WHEN 'abinjosephpoppy267@gmail.com' THEN 'Abin Joseph'
    WHEN 'abinninan2003@gmail.com' THEN 'Abin Ninan'
    WHEN 'abjavijay87@gmail.com' THEN 'Abja Vijay'
    WHEN 'abnasalam2014@gmail.com' THEN 'Abna K'
    WHEN 'abshahfathima@gmail.com' THEN 'Abshah Fathima'
    WHEN 'abvajid199@gmail.com' THEN 'Abdul Vajid M'
    WHEN 'acquinas30@gmail.com' THEN 'Acquina Akbar'
    WHEN 'adheebawafiya001@gmail.com' THEN 'Adeeba Wafiya P M'
    WHEN 'adhilaharoonads1998@gmail.com' THEN 'Adhila Haroon'
    WHEN 'adhilapadhila@gmail.com' THEN 'Adhila P'
    WHEN 'adhithiadhi04@gmail.com' THEN 'Adhithi Uday'
    WHEN 'adhithyarkrishna@gmail.com' THEN 'Adhithya R Krishna'
    WHEN 'adilamukkattil@gmail.com' THEN 'Adila'
    WHEN 'adilamukkattil@psychology.du.ac.in' THEN 'Adila M'
    WHEN 'adithyaajith29@gmail.com' THEN 'Adithya V'
    WHEN 'adithyap016@gmail.com' THEN 'Adithya P'
    WHEN 'adithyapurushothaman75@gmail.com' THEN 'Adithya T M'
    WHEN 'adyamsidharth@gmail.com' THEN 'Adya M Sidharth'
    WHEN 'afeedaabdullakk@gmail.com' THEN 'Afeeda Abdulla'
    WHEN 'afifaamassery@gmail.com' THEN 'Afifa Amassery'
    WHEN 'aflahashifa45@gmail.com' THEN 'Aflaha Shifa'
    WHEN 'aflahkhira@gmail.com' THEN 'Aflah K'
    WHEN 'Aflahzaman16@gmail.com' THEN 'Aflah Zaman K'
    WHEN 'afrahasharaf314@gmail.com' THEN 'Afrah Asharaf'
    WHEN 'afrakabeer4321@gmail.com' THEN 'Afra Kabeer'
    WHEN 'afranaashraf558@gmail.com' THEN 'Afrana T P'
    WHEN 'afrathpp2235@gamil.com' THEN 'Afrath P P'
    WHEN 'afrinbadhar@gmail.com' THEN 'AFRIN BADHAR'
    WHEN 'afsalparengal091@gmail.com' THEN 'Muhammed Afsal'
    WHEN 'afzalkc@gmail.com' THEN 'Afzal K C'
    WHEN 'aghilnazim@gmail.com' THEN 'Aghil Nasim'
    WHEN 'ahalyaambika1998@gmail.Com' THEN 'Ahalya Akhil'
    WHEN 'ahmedmajeed1000@gmail.com' THEN 'Ahmed Abdulmajeed Qaid Mohammed'
    WHEN 'ahshakir786@gmail.com' THEN 'Shakir'
    WHEN 'ahujarajakku@gmail.com' THEN 'Ahuja Raj'
    WHEN 'aileenranjit1999@gmail.com' THEN 'Aileen Kozhichira Ranjit'
    WHEN 'aingelam123@gmail.com' THEN 'Aingela Mulakkalethu Shibu'
    WHEN 'aishamuthalib24@gmail.com' THEN 'Ayishabi P P'
    WHEN 'aishurups.aiswarya@gmail.com' THEN 'Aiswarya Roopesh'
    WHEN 'aishwaryadilkush29@gmail.com' THEN 'Aishwarya Dilkush'
    WHEN 'aiswariyanath2@gmail.com' THEN 'Aiswariya Bhagyanath'
    WHEN 'aiswaryaa296@gmail.com' THEN 'Aiswarya Anilkumar'
    WHEN 'aiswaryab836@gmail.com' THEN 'Aiswarya Babu'
    WHEN 'aiswaryakadavath@gmail.com' THEN 'Aiswaryalakshmi S'
    WHEN 'aiswaryapavithran47@gmail.com' THEN 'Aiswarya V'
    WHEN 'aiswaryapsycho@gmail.com' THEN 'Aiswarya G K'
    WHEN 'aiswaryaravimay03@gmail.com' THEN 'Aiswarya Ravi'
    WHEN 'ajanyakoyiloth1999@gmail.com' THEN 'Ajanya K'
    WHEN 'ajasrashida3232@gmail.com' THEN 'Rashida K V'
    WHEN 'ajaypayyoli@gmail.com' THEN 'Ajay Vijayan'
    WHEN 'ajeenajoseph@lissah.com' THEN 'Ajeena Joseph'
    WHEN 'ajumuhd2@gmail.com' THEN 'Muhammed Aju'
    WHEN 'akhilapv75@gmail.com' THEN 'Akila Ramachandran PV'
    WHEN 'akhilnaduvilv007@gmail.com' THEN 'Akhil Krishnan N V'
    WHEN 'akhitha66@gmail.com' THEN 'Akhitha Davis'
    WHEN 'akshayamolcp@gmail.com' THEN 'Akshaya C P'
    WHEN 'akshayar467@gmail.com' THEN 'Akshaya R'
    WHEN 'Akshays.sadanandan@gmil.com' THEN 'Akshay s'
    WHEN 'alanageorge1015@gmail.com' THEN 'Alana George'
    WHEN 'aleena.merin3135@gmail.com' THEN 'Aleena Merin Jose'
    WHEN 'aleena170011@gmail.com' THEN 'ALEENA VARUGHESE'
    WHEN 'aleenaannmariya9@gmail.com' THEN 'Aleena Ann Mariya'
    WHEN 'aleenajames.research@gmail.com' THEN 'Aleeena James'
    WHEN 'aleenaraju203@gmail.com' THEN 'Aleena R'
    WHEN 'aleenaralex@gmail.com' THEN 'Aleena Rachu Alex'
    WHEN 'aleenarg2101@gmail.com' THEN 'Aleena R Gopan'
    WHEN 'aleenavarghese99@gmail.com' THEN 'Aleena Varughese'
    WHEN 'aleenavarughese95@gmail.com' THEN 'Aleena Varughese'
    WHEN 'aleenawilson59@gmail.com' THEN 'Aleena Wilson'
    WHEN 'aleeshacbasheer@gmail.com' THEN 'Aleesha C Basheer'
    WHEN 'aleetamathew28@gmail.com' THEN 'Aleeta T Mathew'
    WHEN 'alenarosealex2001@gmail.com' THEN 'Alena Rose Alex'
    WHEN 'alinaammu0@gmail.com' THEN 'Alina Grace Thomas'
    WHEN 'alinathomas740@gmail.com' THEN 'Alina Grace Thomas'
    WHEN 'alishamathew267@gmail.com' THEN 'Alisha Mathew'
    WHEN 'aliyaasathar@gmail.com' THEN 'Aliya A Sathar'
    WHEN 'alkakrishnaofficial@gmail.com' THEN 'Alka Krishna'
    WHEN 'alkas022004@gmail.com' THEN 'Alka S'
    WHEN 'alkereethlal@gmail.com' THEN 'Ancy Lal'
    WHEN 'almuneerar@gmail.com' THEN 'Al Muneera R'
    WHEN 'alphinrockz123@gmail.com' THEN 'Alphin V John'
    WHEN 'alveenabiju06@gmail.com' THEN 'Alveena'
    WHEN 'alwinpaulalias@gmail.com' THEN 'Alwin Paul Alias'
    WHEN 'amalajomy2000@gmail.com' THEN 'Amala Jomy'
    WHEN 'amalasok421@gmail.com' THEN 'Amal Asokkumar'
    WHEN 'amalbnambiar@hotmail.com' THEN 'Amrutha K'
    WHEN 'amalriyadh31@gmail.com' THEN 'Amal Abdul Latheef'
    WHEN 'amalsabujoseph127@gmail.com' THEN 'Amal Sabu'
    WHEN 'amalshaammalu94@gmail.com' THEN 'Amalsha P T'
    WHEN 'amanikavngl@gmail.com' THEN 'Amani Jaleel Kavungal'
    WHEN 'amanyabaiju729@gmail.com' THEN 'Amanya Baiju'
    WHEN 'ambreenanavas@gmail.com' THEN 'Ambreena'
    WHEN 'ameenaameen559@gmail.com' THEN 'Ameena Ameen M M'
    WHEN 'ameenambmattathil@gmail.com' THEN 'Ameena M B'
    WHEN 'ameenasfazil2003@gmail.com' THEN 'Ameena S Fazil'
    WHEN 'ameenasharin2001@gmail.com' THEN 'Sharin P K'
    WHEN 'ameenashraf1272@gmail.com' THEN 'Ameen Ashraf'
    WHEN 'ameenathsahla@gmail.com' THEN 'Ameenath Sahla'
    WHEN 'ameenmuhammedkc@gmail.com' THEN 'Ameen Muhammed K C'
    WHEN 'amihameena@gmail.com' THEN 'Ameena'
    WHEN 'aminanaseela2000@gmail.com' THEN 'Amina Naseela Parambath Kandy'
    WHEN 'aminanihala8@gmail.com' THEN 'Amina Nihala'
    WHEN 'amithasaji2203@gmail.com' THEN 'Amitha A S'
    WHEN 'amiyamariyam1997@gmail.com' THEN 'Amiya Mariyam Tomy'
    WHEN 'amlaammu24@gmail.com' THEN 'Amla M C'
    WHEN 'ammucu3902@gmail.com' THEN 'Aswathy Lekshmi S'
    WHEN 'amnafathimacsc@gmail.com' THEN 'Amna Fathima T'
    WHEN 'amnah.fathima110@gmail.com' THEN 'Amna Fathima K K'
    WHEN 'amnamna09@gmail.com' THEN 'Amna Kamarudeen'
    WHEN 'amnathadathil2000@gmail.com' THEN 'Amna T'
    WHEN 'amnusami206@gmail.com' THEN 'Amna M P'
    WHEN 'amritabhasi2002@gamil.com' THEN 'Amrita Bhasi'
    WHEN 'amrithakr02@gmail.com' THEN 'Amritha K R'
    WHEN 'amrithamjacob@gmail.com' THEN 'Amritha Jacob'
    WHEN 'amrithawork25@gmail.com' THEN 'Amritha M'
    WHEN 'amruthaettoth@gmail.com' THEN 'Amrutha Unnikrishnan'
    WHEN 'amruthakt2001@gmail.com' THEN 'Amrutha K T'
    WHEN 'Amruthapradeep3201@gmail.com' THEN 'Amrutha Pradeep'
    WHEN 'amruthavkm2005@gmail.com' THEN 'Amrutha M V'
    WHEN 'anaghaaa26@gmail.com' THEN 'Anagha Vinod'
    WHEN 'Anaghamanikandanmt@gmail.com' THEN 'Anagha M T'
    WHEN 'anaghamanoharofficial@gmail.com' THEN 'Anagha Manohar'
    WHEN 'anaghaprasanth653@gmail.com' THEN 'Anagha P'
    WHEN 'anaghas089@gmail.com' THEN 'Anagha S'
    WHEN 'anaghasnair422@gmail.com' THEN 'Anagha Pradeep'
    WHEN 'anaghasunny13@gmail.com' THEN 'Anagha Sunny'
    WHEN 'Anaghavperingalath24mscpsy@lissah.com' THEN 'Anagha V Peringalath'
    WHEN 'anakhab06@gmail.com' THEN 'Anakha Baiju'
    WHEN 'anakhasadan@gmail.com' THEN 'Anakha Sadan'
    WHEN 'anamikaashokan28@gmail.com' THEN 'Anamika K'
    WHEN 'anamikacreji@gmail.com' THEN 'Anamika C Reji'
    WHEN 'anamikajyothis@gmail.com' THEN 'Anamika Jyothis'
    WHEN 'anamikarajan593@gmail.com' THEN 'Anamika P P'
    WHEN 'ananovajose1217@gmail.com' THEN 'Ananova Jose'
    WHEN 'ananya4756kvk@gmail.com' THEN 'Ananya Josephine B'
    WHEN 'ananyaharidas26@gmail.com' THEN 'Ananya Haridas'
    WHEN 'ananyap657@gmail.com' THEN 'Ananya P'
    WHEN 'anaswaraunnikrishnan69@gmail.com' THEN 'Anaswara Unnikrishnan'
    WHEN 'anavadyauday111455@gmail.com' THEN 'Anavadya Uday'
    WHEN 'ancyperingattu@gmail.com' THEN 'Ancy P Aniyan'
    WHEN 'Andraanandvp20@gmail.com' THEN 'Ardra Anand V P'
    WHEN 'aneenalizbeth@gmail.com' THEN 'Aneena Elizabeth Verghese'
    WHEN 'aneeshamathewp@gmail.com' THEN 'Aneesha Mathew P'
    WHEN 'aneetaannaroy5001@gmail.com' THEN 'Aneeta Anna Roy'
    WHEN 'angelancysara@gmail.com' THEN 'Ancy Sara Mathew'
    WHEN 'angelinbinoy2002@gmail.com' THEN 'Angelin Binoy'
    WHEN 'angelmariajose43@gmail.com' THEN 'Angel Maria Jose'
    WHEN 'angelvargheseangel@gmail.com' THEN 'Angel Elsa Varghese'
    WHEN 'aniesapa5@gmail.com' THEN 'Aniesa P A'
    WHEN 'animina2233@gmail.com' THEN 'Mina M H'
    WHEN 'anishavibin11@gmail.com' THEN 'Anisha M O'
    WHEN 'anittajaison0@gmail.com' THEN 'Anitta Jaison'
    WHEN 'anjali.edu5@gmail.com' THEN 'Anjali A'
    WHEN 'anjali2002dec@gmail.com' THEN 'P Anjali'
    WHEN 'Anjalichoyan274@gmail.com' THEN 'Anjali Choyan'
    WHEN 'anjalirejila1975@gmail.com' THEN 'Anjali R'
    WHEN 'anjalivijayakumar201@gmail.com' THEN 'Anjali Vijayakumar'
    WHEN 'anjanaks7592@gmail.com' THEN 'Anjana K S'
    WHEN 'anjanaroseponnu@gmail.com' THEN 'Anjana Rose P R'
    WHEN 'anjanasobm@gmail.com' THEN 'Anjana'
    WHEN 'anjanawilson3@gmail.com' THEN 'Anjana Wilson'
    WHEN 'anjithacnambiar@gmail.com' THEN 'Anjitha C Nambiar'
    WHEN 'anjithaj2000@gmail.com' THEN 'Anjitha J'
    WHEN 'anjithasanthos@gmail.com' THEN 'Anjitha Santhosh'
    WHEN 'anjubkmpm@gmail.com' THEN 'Anju B K'
    WHEN 'anjuhibz@gmail.com' THEN 'Fathima Hiba'
    WHEN 'anjusunil002@gmail.com' THEN 'Anju Sunil'
    WHEN 'anjuthankachan715@gmail.com' THEN 'Anju Thankachan'
    WHEN 'anjutpwk@gmail.com' THEN 'Anjali C'
    WHEN 'ankpkl500@gmail.com' THEN 'Anoof K'
    WHEN 'annaleena256@gmail.com' THEN 'Ann Elsa Varghese'
    WHEN 'annamariakuriakose50@gmail.com' THEN 'Annamaria Kuriakose'
    WHEN 'annecandace04@gmail.com' THEN 'Candace Anne Shaju'
    WHEN 'anniemariampsy.8@gmail.com' THEN 'Annie Mariam'
    WHEN 'annm.thomas.05@gmail.com' THEN 'Ann Mariya Thomas'
    WHEN 'annmaria.arikupurath@gmail.com' THEN 'Ann Maria Samuel'
    WHEN 'annmariaj311@gmail.com' THEN 'Ann Maria Jose'
    WHEN 'annmariyakml@gmail.com' THEN 'Ann Mariya Shaji'
    WHEN 'annmaryjose895@gmail.com' THEN 'Annmary Jose'
    WHEN 'annmarythomas2225@gmail.com' THEN 'Ann Mary Thomas'
    WHEN 'annnithyadawarave@gmail.com' THEN 'Ann Nithya Dawarave'
    WHEN 'annsiby2004@gmail.com' THEN 'Ann Theresa Siby'
    WHEN 'annsusaneldho485@gmail.com' THEN 'ANN SUSAN ELDHO'
    WHEN 'annuann2001@gmail.com' THEN 'Annu Ann Abraham'
    WHEN 'annuyadav101997@gmail.com' THEN 'Annu'
    WHEN 'anoopsivadas26@gmail.com' THEN 'Anoop Sivadas'
    WHEN 'anshamic1891@gmail.com' THEN 'Aneesha Michael'
    WHEN 'anshid101anu@gmail.com' THEN 'Mohammed Anshid U P'
    WHEN 'ansiiansila586@gmail.com' THEN 'Ansila V P'
    WHEN 'antriyabais@gmail.com' THEN 'Antriya Bais'
    WHEN 'anuanugraha.2102@gmail.com' THEN 'Anumol S S'
    WHEN 'anugeorge211@gmail.com' THEN 'Anu George'
    WHEN 'anugrahamerin@gmail.com' THEN 'Anugraha Merin Rajan'
    WHEN 'anukagopinathp@gmail.com' THEN 'Anuka'
    WHEN 'anup63479@gmail.com' THEN 'Anu Paul'
    WHEN 'anupama54652@gmail.com' THEN 'Anupama A'
    WHEN 'anupamacn2001@gmail.com' THEN 'Anupama C N'
    WHEN 'anupamakonnakkal2001@gmail.com' THEN 'Anupama Surendran K'
    WHEN 'anuragvadavathi18@gmail.com' THEN 'Anurag V'
    WHEN 'anuranjana28@gmail.com' THEN 'Anuranjana E'
    WHEN 'anushaajayakumar4@gmail.com' THEN 'Anusha Ajayakumar'
    WHEN 'anushanz111@gmail.com' THEN 'Shana'
    WHEN 'anusmithapravi@gmail.com' THEN 'Anusmitha Praveen'
    WHEN 'anusreearumughan@gmail.com' THEN 'Anusree K A'
    WHEN 'anvarsha01010@gmail.com' THEN 'Anvar Sha'
    WHEN 'anziaanzi26@gmail.com' THEN 'Anzia S F'
    WHEN 'anzpersonal425@gmail.com' THEN 'Anzila Bai'
    WHEN 'aparna21ae@gmail.com' THEN 'Aparna Narayanan'
    WHEN 'aparnaann97@gmail.com' THEN 'Aparna Anna Mathew'
    WHEN 'aparnamohanan13@gmail.com' THEN 'Aparna K Mohanan'
    WHEN 'aparnasajikumar22@gmail.com' THEN 'Aparna S'
    WHEN 'aparnaunni213@gmail.com' THEN 'Aparna Unni'
    WHEN 'apmnambiar@gmail.com' THEN 'Aparna M Nambiar'
    WHEN 'aradhanakv770@gmail.com' THEN 'Aradhana K V'
    WHEN 'aramanayilneha@gmail' THEN 'Neha Aramanayil'
    WHEN 'arathips94@gmail.com' THEN 'P S ARATHI'
    WHEN 'arathysv427@gmail.com' THEN 'Arathy S V'
    WHEN 'archanakanil26@gmail.com' THEN 'Archana K'
    WHEN 'archanakdas2003@gmail.com' THEN 'Archana K Das'
    WHEN 'archnayadav00@gmail.com' THEN 'Archana'
    WHEN 'ardhrapsugunan@gmail.com' THEN 'Ardhra P Sugunan'
    WHEN 'areebaschamnad@gmail.com' THEN 'Aysha Areeba'
    WHEN 'arjun2004amithu@gmail.com' THEN 'Arjun V'
    WHEN 'Arjunms8156@gmail.com' THEN 'Arjun M S'
    WHEN 'Arjunpsofficial@gmail.com' THEN 'Arjun P Sathyan'
    WHEN 'aromalmr3@gmail.com' THEN 'Aromal M R'
    WHEN 'arpithatroshan@gmail.com' THEN 'Arpitha T Roshan'
    WHEN 'arsha2003avd@gmail.com' THEN 'Arsha V D'
    WHEN 'arshaddida@gmail.com' THEN 'Arshida Shihab'
    WHEN 'arshamandody@gmail.com' THEN 'Arsha M'
    WHEN 'arshasm9785@gmail.com' THEN 'Arsha S Manju'
    WHEN 'aruanna2004@gamil.com' THEN 'Arunima R'
    WHEN 'arundeepkp@gmail.com' THEN 'Ziya Arun'
    WHEN 'arundhathias1999@gmail.com' THEN 'Arundhathi A S'
    WHEN 'aryadamodaran2001@gmail.com' THEN 'Arya P D'
    WHEN 'aryagulmohar03@gmail.com' THEN 'Arya K'
    WHEN 'aryamohananm@gmail.com' THEN 'Arya Mohan'
    WHEN 'aryapappanamcode@gmail.com' THEN 'Arya B S'
    WHEN 'aryaprasad0708@gmail.com' THEN 'Arya Prasad'
    WHEN 'aryasreek86@gmail.com' THEN 'Aryasree K'
    WHEN 'asbiya.safar.0@gmail.com' THEN 'Asbiya P S'
    WHEN 'Aschaswani@gmail.Com' THEN 'Aswani M B'
    WHEN 'aseenakanoth@gmail.com' THEN 'Aseena'
    WHEN 'Ashermichaelbabu@gmail.com' THEN 'Asher Michael Babu'
    WHEN 'ashfinaap67@gmail.com' THEN 'Ashfina'
    WHEN 'ashifa983664@gmail.com' THEN 'Ashifa M'
    WHEN 'Ashifaaachi456@gmail.com' THEN 'Ashifa A'
    WHEN 'ashikashikmohammed.f@gmail.com' THEN 'Ashik Mohammed F'
    WHEN 'ashinasolomon10@gmail.com' THEN 'Ashina Solomon'
    WHEN 'ashithaashitha005@gmail.com' THEN 'Ashitha M'
    WHEN 'ashithar111@gmail.com' THEN 'Ashitha R'
    WHEN 'ashlinjames18@gmail.com' THEN 'Ashlin James'
    WHEN 'ashnak2020@gmail.com' THEN 'Ashna K'
    WHEN 'ashnarahim10@gmail.com' THEN 'Ashnamol Rahim'
    WHEN 'ashwinigsatish@gmail.com' THEN 'Ashwini Satish'
    WHEN 'ashya.psychologist@gmail.com' THEN 'Ashya K Salim'
    WHEN 'aslahaazeez777@gmail.com' THEN 'Aslaha Sulthana E K'
    WHEN 'asmashb@gmail.com' THEN 'Asma Shuaib'
    WHEN 'asna99ashraf@gmail.com' THEN 'Fathimath Asna'
    WHEN 'asnaabdulkareem32@gmail.com' THEN 'Asna Abdulkareem'
    WHEN 'asnaasees99@gmail.com' THEN 'Asna K'
    WHEN 'asnamk904@gmail.com' THEN 'Asna Mk'
    WHEN 'asnaparveen8589@gmail.com' THEN 'Asna Parveen K P'
    WHEN 'asnathpsy@gmail.com' THEN 'Asnath Mol'
    WHEN 'asnats2021@gmail.com' THEN 'Asna T S'
    WHEN 'Aswanibiju640@gmail.com' THEN 'Aswani M B'
    WHEN 'aswanimbijuachu@gmail.com' THEN 'Aswani M B'
    WHEN 'aswathiat123@gmail.com' THEN 'Aswathi Das'
    WHEN 'aswathibalan002@gmail.com' THEN 'Aswathi Balan'
    WHEN 'aswathiek012@gmail.com' THEN 'Aswathi E K'
    WHEN 'aswathikwdr01@gmail.com' THEN 'Aswathi K'
    WHEN 'aswathirajkaravoor@gmail.com' THEN 'Aswathi Raj'
    WHEN 'aswathy.cvv230119@cvv.ac.in' THEN 'Aswathy Jothish'
    WHEN 'aswathyammu3902@gmail.com' THEN 'Aswathy Lekshmi S'
    WHEN 'aswathyganga.ganga789@gmail.com' THEN 'Aswathi Ganga'
    WHEN 'aswathym430@gmail.com' THEN 'Aswathy M'
    WHEN 'aswathymavoor@gmail.com' THEN 'ASWATHY A R'
    WHEN 'aswathymaya703@gmail.com' THEN 'Aswathy P'
    WHEN 'aswathysuresh703@gmail.com' THEN 'Aswathy P'
    WHEN 'athak2303@gmail.com' THEN 'Athira Ashok'
    WHEN 'athiramohanan2k@gmail.com' THEN 'Athira K'
    WHEN 'athirapponnath@gmail.com' THEN 'Athira P'
    WHEN 'athiratbabu1996@gmail.com' THEN 'Athira T Babu'
    WHEN 'athiratsathu@gmail.com' THEN 'Athira T S'
    WHEN 'athulathulkrishna768@gmail.com' THEN 'Athul krishna v'
    WHEN 'athulya.viswam99@gmail.com' THEN 'Athulya N V'
    WHEN 'athulya10000@gmail.com' THEN 'Athulya M Ramesh'
    WHEN 'athulyaadish@gmail.com' THEN 'Athulya T K'
    WHEN 'athulyajayasree245@gmail.com' THEN 'Athulya J'
    WHEN 'atmanoopcst@gmail.com' THEN 'Anoop T M'
    WHEN 'avanthika680@gmail.com' THEN 'Avanthika S'
    WHEN 'avkrk192220@gmail.com' THEN 'Avanthika R'
    WHEN 'ayanapr2002@gmail.com' THEN 'Ayana P R'
    WHEN 'ayishafaiha.ke@gmail.com' THEN 'Ayisha Faiha K E'
    WHEN 'ayishakeit@gmail.com' THEN 'Ayisha Bibi'
    WHEN 'ayishanada917@gmail.com' THEN 'Ayisha Nada V K'
    WHEN 'ayishariza987@gmail.com' THEN 'Ayisha Riza'
    WHEN 'ayishashimshna@gmail.com' THEN 'Ayisha Shimshna'
    WHEN 'aymansaad5775@gmail.com' THEN 'Ayman Saad K'
    WHEN 'ayshadiyapk@gmail.com' THEN 'Ayisha Diya P K'
    WHEN 'ayshahiba2626@gmail.com' THEN 'Ayisha Hiba'
    WHEN 'ayshamumthas@gmail.com' THEN 'Aysha Mumthas Jahan'
    WHEN 'ayshanourinnoushin@gmail.com' THEN 'Fathima Noushin C M'
    WHEN 'ayshaparveen3581@gmail.com' THEN 'Aysha Parveen'
    WHEN 'ayshasherin835@gmail.com' THEN 'Hasna P T'
    WHEN 'Ayshfibi2001@gmail.com' THEN 'Ayisha Fibi'
    WHEN 'ayshu4801@gmail.com' THEN 'Aiswarya Gouri'
    WHEN 'azeefaabdhulkareem@gmail.com' THEN 'Azeefa Abdul Kareem'
    WHEN 'badirabadarudheen24@gmail.com' THEN 'Badira M T P'
    WHEN 'balkeeskavarodi@gmail.com' THEN 'Shabana Balkksss'
    WHEN 'barsleeby@gmail.com' THEN 'Barsleeby Alex Daniel'
    WHEN 'basimvsalim@gmail.com' THEN 'Basim Salim'
    WHEN 'bb2217539@gmail.com' THEN 'Shekha V S'
    WHEN 'beeyavathyara1117@gmail.com' THEN 'Beeya Ann Thomas'
    WHEN 'benignrinsha@gmail.com' THEN 'Rinsha K A'
    WHEN 'betcysimon01@gmail.com' THEN 'Betcy Simon'
    WHEN 'bhagupsy@gmail.com' THEN 'Bhagyanadh M A'
    WHEN 'bhagyalakshmisreekumar275@gmail.com' THEN 'Bhagyalakshmi Sreekumar'
    WHEN 'bhagyashree3504@gmail.com' THEN 'Bhagyashree S'
    WHEN 'bhavyasreedileepkumar@gmail.com' THEN 'Bhavyasree Dileepkumar'
    WHEN 'binshi871@gmail.com' THEN 'Binshida Kudukkil Kochampalli'
    WHEN 'binthazeezhasna@gmail.com' THEN 'Hasna Pk'
    WHEN 'binulakshmi000@gmail.com' THEN 'Binu Lakshmi'
    WHEN 'bismi2626@gmail.com' THEN 'Bismiya S'
    WHEN 'bksjameesha@gmail.com' THEN 'Maimoona Jameesha B K'
    WHEN 'blessybejohn@gmail.com' THEN 'Blessy Bejohn'
    WHEN 'blessypsunny13@gmail.com' THEN 'Blessy P Sunny'
    WHEN 'care@drramziya.com' THEN 'Dr Ramziya Rahmath C'
    WHEN 'catherinebabu777@gmail.com' THEN 'Catherine Babu'
    WHEN 'catherinempereira17@gmail.com' THEN 'Catherin M Pereira'
    WHEN 'Cbdivya@gmail.com' THEN 'Divya C B'
    WHEN 'celinriyagijo@gmail.com' THEN 'Riya Gijo'
    WHEN 'cfathimaraniya@gmail.com' THEN 'Fathima Raniya C'
    WHEN 'chaithanyacmadathil@gmail.com' THEN 'Chaithanya C M'
    WHEN 'chaithrapp15@gmail.com' THEN 'Chaithra P P'
    WHEN 'chandinisanthosh99@gmail.com' THEN 'Chandini Santhosh'
    WHEN 'chesh.immanuwin@gmail.com' THEN 'Cheshwin P Chellappan'
    WHEN 'chitra.anand.2001@gmail.com' THEN 'Chitra Anand'
    WHEN 'cholopsy7@gmail.com' THEN 'Sruthi M Dooth'
    WHEN 'christeenaleslie@gmail.com' THEN 'Christeena Leslie'
    WHEN 'chulluzz3955@gmail.com' THEN 'Fathima Sherin'
    WHEN 'cmgayathri08@gmail.com' THEN 'Gayathri C M'
    WHEN 'cmvayathri08@gmail.com' THEN 'Gayathri C M'
    WHEN 'connectme.sruthiks@gmail.com' THEN 'Sruthi K S'
    WHEN 'cpamritadas1996@gmail.com' THEN 'Amritadas C P'
    WHEN 'cpvafiya@gmail.com' THEN 'Vafiya C P'
    WHEN 'cristojoseph2255@gmail.com' THEN 'Cristo Joseph'
    WHEN 'd0017on@gmail.com' THEN 'Don K Thomas'
    WHEN 'daleena30@gmail.com' THEN 'Daleena Selin'
    WHEN 'ddiyaelizabeth@gmail.com' THEN 'Diya Elizabeth Dennis'
    WHEN 'deenakvarghese10@gmail.com' THEN 'Deena K Varghese'
    WHEN 'Deepafrancism@gmail.com' THEN 'Deepa Francis'
    WHEN 'deepthijayank95@gmail.com' THEN 'Deepthi Jayan K'
    WHEN 'delnadennis27@gmail.com' THEN 'Delna Dennis'
    WHEN 'devadevazz799@gmail.com' THEN 'Devapriya P'
    WHEN 'devapriyarahulraj@gmail.com' THEN 'Devapriya Rahul'
    WHEN 'devendudinesh1723@gmail.com' THEN 'Devendu Dinesh'
    WHEN 'devikaedavana@gmail.com' THEN 'Devika E'
    WHEN 'devikaka910@gmail.com' THEN 'Devika K A'
    WHEN 'devikant96@gmail.com' THEN 'Devika N T'
    WHEN 'devikasethu87@gmail.com' THEN 'Devika Mohan G'
    WHEN 'devikasundar8@gmail.com' THEN 'Devika Sundar'
    WHEN 'devikasunilm@gmail.com' THEN 'Devika Sunil'
    WHEN 'dhanuemathew@gmail.com' THEN 'Dhanu Elza Mathew'
    WHEN 'dharsanamm70@gmail.com' THEN 'Dharsana M M'
    WHEN 'dhilshisaramathai@gmail.com' THEN 'Dhilshi Mathai'
    WHEN 'Dhiyansmom@gmail.com' THEN 'Muhsina M P'
    WHEN 'dhrisyamariyam@gmail.com' THEN 'Dhrisya Baby'
    WHEN 'didamhd8@gmail.com' THEN 'Rida E M'
    WHEN 'dijaahammed2010@gmail.com' THEN 'Josna L Jose'
    WHEN 'dilfanaa999@gmail.com' THEN 'Dilfa'
    WHEN 'dilnaemt@gmail.com' THEN 'Emtesan Dilna'
    WHEN 'dilnaluckman@gmail.com' THEN 'Dilna O K'
    WHEN 'dilnaprasad15@gmail.com' THEN 'Dilna K O'
    WHEN 'dilnasebastian2003@gmail.com' THEN 'Dilna K S'
    WHEN 'dilnashefin21@gmail.com' THEN 'Dilna Shefin M'
    WHEN 'dilrosebanu77@gmail.com' THEN 'Dil Rose Banu'
    WHEN 'dilsha.sathaja@gmail.com' THEN 'Dilshad Sarthaja'
    WHEN 'dilshadaraihan@gmail.com' THEN 'Dilshada Raihan V P'
    WHEN 'diluabid2001@gmail.com' THEN 'Dilu Abid'
    WHEN 'divyagpsy@gmail.com' THEN 'Divya G Vimal'
    WHEN 'divyagpsy16@gmail.com' THEN 'Divya G Vimal'
    WHEN 'divyamangulam547113@gmail.com' THEN 'Divya M'
    WHEN 'divyarajeevsr@gmail.com' THEN 'Divya Rajeev'
    WHEN 'divyasree2112003@gmail.com' THEN 'Divyasree P K'
    WHEN 'divyasusan2003@gmail.com' THEN 'Divya Susan Varughese'
    WHEN 'diya10480@gmail.com' THEN 'Diya George'
    WHEN 'diyaakbarkp2@gmail.com' THEN 'Shadiya Akbar K P'
    WHEN 'diyaannjose04@gmail.com' THEN 'Diya Ann Jose'
    WHEN 'diyabsr1@gmail.com' THEN 'Diya S Basheer'
    WHEN 'diyadevadasmorikkara@gmail.com' THEN 'Diya K'
    WHEN 'diyamurali.dm@gmail.com' THEN 'Diya K M'
    WHEN 'diyaneelimr@gmail.com' THEN 'Diya Neelim Rasool M'
    WHEN 'diyasana9744927272@gmail.com' THEN 'Diya Sana K N'
    WHEN 'diyasharish2@gmail.com' THEN 'Diya S Harish'
    WHEN 'dmariamjimmy@gmail.com' THEN 'Diya Mariam Jimmy'
    WHEN 'donabaiju.mar@gmail.com' THEN 'Dona Baiju'
    WHEN 'donarosedavis@gmail.com' THEN 'Dona Davis'
    WHEN 'donayesudas@gmail.com' THEN 'Dona Yesudas'
    WHEN 'dramrutakr@gmail.com' THEN 'Amruta K R'
    WHEN 'drishyaprasannaraj29@gmail.com' THEN 'Drishya Prasanna Raj'
    WHEN 'drisyaspm@gmail.com' THEN 'Drisya S'
    WHEN 'edwinpj2001@gmail.com' THEN 'Edwin P J'
    WHEN 'eleshmol2002@gmail.com' THEN 'Elesha Mol Rajan'
    WHEN 'elizabethkeziya@gmail.com' THEN 'Keziya Elizabeth Aji'
    WHEN 'elizabethmathew285@gmail.com' THEN 'Elizabeth Mathew'
    WHEN 'elizabethsaraj@gmail.com' THEN 'Elizabeth Sara Joseph'
    WHEN 'Emiltresa03@gmail.com' THEN 'Emil Tresa George'
    WHEN 'emmanustanley812@gmail.com' THEN 'Emmanuel Stanley'
    WHEN 'eprisba@gmail.com' THEN 'Risba E P'
    WHEN 'epunais111@gmail.com' THEN 'Muhammed Unais E P'
    WHEN 'er.ahmedsahal@gmail.com' THEN 'Ahmed Sahal K P'
    WHEN 'erjimson@gmail.com' THEN 'Jimson E R'
    WHEN 'eshafathima2006@gmail.com' THEN 'Esha Fathima K M'
    WHEN 'euhanoed@gmail.com' THEN 'Euhano Titus'
    WHEN 'evadk2004@gmail.com' THEN 'Eva D Kokken'
    WHEN 'exams2025amna@gmail.com' THEN 'Amna Kamarudeen'
    WHEN 'excelrafna@gmail.com' THEN 'Fathimath Rafna'
    WHEN 'eyaelysian@gmail.com' THEN 'Jamshiya'
    WHEN 'faaiirshad@gmqil.com' THEN 'Fahida C P'
    WHEN 'faathimaaah61@gmail.com' THEN 'Fathima Mashhoori'
    WHEN 'fabeehasameer@gmail.com' THEN 'Fabeeha Sameer'
    WHEN 'fadhilpulath@gmail.com' THEN 'Muhammed Fadhil P'
    WHEN 'fadilmustafacv02@gmail.com' THEN 'Fadil Musthafa'
    WHEN 'fadiyash21@gmail.com' THEN 'Fadiya Ashraf'
    WHEN 'fahidariyas@gmail.com' THEN 'Fahida P P'
    WHEN 'faisytky@gmail.com' THEN 'Faisy'
    WHEN 'fajnarahman@gmail.com' THEN 'Fathimathul Fajna M'
    WHEN 'fami22499@gmail.com' THEN 'Fathima Fami E K'
    WHEN 'faminamundeth@gmail.com' THEN 'Famina M A'
    WHEN 'farhafaz87@gmail.com' THEN 'Farhana P'
    WHEN 'farhanashameer56@gmail' THEN 'Farhana S Beegum'
    WHEN 'farhanashameer56@gmail.com' THEN 'Farhana S Beegum'
    WHEN 'farsanaam03@gmail.com' THEN 'Farsana A M'
    WHEN 'farsanamol97@gmail.com' THEN 'Farsana Mol M K'
    WHEN 'farsanatasnim89@gmail.com' THEN 'Farsana Tasnim P'
    WHEN 'farshaam16@gmail.com' THEN 'Farsha A M'
    WHEN 'Farwanashabukker@gmail.com' THEN 'Farwana Abubacker Shabukker'
    WHEN 'farzzzmrr57@gmail.com' THEN 'Farzana R'
    WHEN 'faseeha1702@gmail.com' THEN 'Faseeha K Nasarudeen'
    WHEN 'faseelathoombat@gmail.com' THEN 'Faseela T'
    WHEN 'fasnaabeegum13@gmail.com' THEN 'Fasna Beegum'
    WHEN 'fasnashaji090@gmail.com' THEN 'Fasna S'
    WHEN 'Fathifathima0224@gmail.com' THEN 'Fathima Bathool'
    WHEN 'fathii.raz88@gmail.com' THEN 'Fathima Razna'
    WHEN 'fathima.nazrinpk003@gmail.com' THEN 'FATHIMA NAZRIN PK'
    WHEN 'fathima.nediyavilayil@gmail.com' THEN 'Fathima Beegam'
    WHEN 'fathima1812810@gmail.com' THEN 'Fathima I'
    WHEN 'fathimaaik@gmail.com' THEN 'Fathima Ansari'
    WHEN 'fathimaaslahan@gmail.com' THEN 'Fathima Aslaha N'
    WHEN 'fathimabeegam2002@gail.com' THEN 'Fathima Beegam'
    WHEN 'fathimabeegam2002@gmail.com' THEN 'Fathima Beegam'
    WHEN 'fathimabeegam79@gmail.com' THEN 'Fathima Beegam'
    WHEN 'fathimabeeviclt@gmail.com' THEN 'Fathima Beevi K P'
    WHEN 'fathimackfathi@gmail.com' THEN 'Fathima C K'
    WHEN 'fathimadavood619@gmail.com' THEN 'Fathima Dawood'
    WHEN 'fathimafuhada3@gmail.com' THEN 'Fathima Fuhada P M'
    WHEN 'Fathimahanaap@gmail.com' THEN 'Fathimathul Hana A P'
    WHEN 'fathimahibaah114@gmail.com' THEN 'Fathima Hiba P P'
    WHEN 'Fathimahibamv@gmail.com' THEN 'Fathima Hiba M V'
    WHEN 'fathimajafni3463@gmail.com' THEN 'Fathima Jafni'
    WHEN 'fathimaminu30@gmail.com' THEN 'Minu Fathima V A'
    WHEN 'fathimamony123@gmail.com' THEN 'Fathima Mony M S'
    WHEN 'fathimamp531@gmail.com' THEN 'Fathimath Jouhara'
    WHEN 'fathimamusfina462946@gmail.com' THEN 'Fathima Musfina'
    WHEN 'fathimanabav@gmail.com' THEN 'Fathima Naba Ussain Ebrahim V'
    WHEN 'fathimanahla292@gmail.com' THEN 'Fathima Nahla M K'
    WHEN 'fathimanajiyanazir@gmail.com' THEN 'Fathima Najiya'
    WHEN 'fathimanaseehacm@gmail.com' THEN 'Fathima Naseeha C M'
    WHEN 'fathimanaslack@gmail.com' THEN 'Fathima Nasla C K'
    WHEN 'fathimanoora294@gmail.com' THEN 'Fathima Noora'
    WHEN 'fathimanuhavs@gmail.com' THEN 'Fathima Nuha V S'
    WHEN 'fathimaranna81@gmail.com' THEN 'Fathima Ranna K'
    WHEN 'fathimarosly8111@gmail.com' THEN 'Fathima Rosly'
    WHEN 'fathimas52345@gmail.com' THEN 'Fathima S'
    WHEN 'fathimasana9082@gmail.Com' THEN 'Fathima Sana P'
    WHEN 'fathimashahma171@gmail.com' THEN 'Fathima Shahma'
    WHEN 'fathimasherinma786@gmail.com' THEN 'Fathima Sherin'
    WHEN 'fathimasidhyc327@gmail.com' THEN 'Fathima Sidhyc'
    WHEN 'fathimathashreefakp2002@gmail.com' THEN 'Fathima Thashreefa K P'
    WHEN 'fathimathhibakp817@gmail.com' THEN 'Fathimath Hiba K P'
    WHEN 'fathimathulmeha138@gmail.com' THEN 'Fathimathul Meha'
    WHEN 'fathimathusahala2019@gmail.com' THEN 'Fathimathu Sahla'
    WHEN 'fathimathusahda276@gmail.com' THEN 'Fathimathu Sahda N'
    WHEN 'fathimayousuf101@gmail.com' THEN 'Fathima Yousuf'
    WHEN 'fathinizar5000@gmail.com' THEN 'Fathima P M'
    WHEN 'fazconnect@gmail.com' THEN 'Fairuz K M'
    WHEN 'fazilabdu@gmail.com' THEN 'Fazil Abdu'
    WHEN 'fazlafaisal2006@gmail.com' THEN 'Fazla Binth Faisal K K P'
    WHEN 'faznabasheer.8281@gmail.com' THEN 'Fazna Basheer'
    WHEN 'Fazzfarooq13@gmail.com' THEN 'Mohammed Fazil P'
    WHEN 'feabaphilip1998@gmail.com' THEN 'Feaba Philip'
    WHEN 'femijisana172@gmail.com' THEN 'Femi Jisana K P'
    WHEN 'femimuzaffar@gmail.com' THEN 'Fathima Fahmi Chemben'
END
WHERE email IN (
    '13farha@gmail.com', '13mariageorge6@gmail.com', '24ppya101@stellamariscollege.edu.in',
    '7atsevenashiquemohammad@gmail.com', 'aadhiaddz@gmail.com', 'aadithyanpradeep2006@gmail.com',
    'aaishamdy@gmail.com', 'aarathii.p.s@gmail.com', 'abbadabi13@gmail.com', 'abbiud55@gmail.com',
    'abdufrkzz@gmail.com', 'abdulraoofkk313@gmail.com', 'abduraheem1930@gmail.com',
    'abhirambhaskar041@gmail.com', 'abhirambhaskarp@gmail.com', 'abhiramiammus2001@gmail.Com',
    'abhiramii.es@gmail.com', 'abhiramm077@gmail.com', 'abhisreesprasad@gmail.com', 'abidakp21@gmail.com',
    'Abijithnarayanan25@gmail.com', 'abilfidhanfidz@gmail.com', 'abinjosephpoppy267@gmail.com',
    'abinninan2003@gmail.com', 'abjavijay87@gmail.com', 'abnasalam2014@gmail.com', 'abshahfathima@gmail.com',
    'abvajid199@gmail.com', 'acquinas30@gmail.com', 'adheebawafiya001@gmail.com',
    'adhilaharoonads1998@gmail.com', 'adhilapadhila@gmail.com', 'adhithiadhi04@gmail.com',
    'adhithyarkrishna@gmail.com', 'adilamukkattil@gmail.com', 'adilamukkattil@psychology.du.ac.in',
    'adithyaajith29@gmail.com', 'adithyap016@gmail.com', 'adithyapurushothaman75@gmail.com',
    'adyamsidharth@gmail.com', 'afeedaabdullakk@gmail.com', 'afifaamassery@gmail.com',
    'aflahashifa45@gmail.com', 'aflahkhira@gmail.com', 'Aflahzaman16@gmail.com', 'afrahasharaf314@gmail.com',
    'afrakabeer4321@gmail.com', 'afranaashraf558@gmail.com', 'afrathpp2235@gamil.com', 'afrinbadhar@gmail.com',
    'afsalparengal091@gmail.com', 'afzalkc@gmail.com', 'aghilnazim@gmail.com', 'ahalyaambika1998@gmail.Com',
    'ahmedmajeed1000@gmail.com', 'ahshakir786@gmail.com', 'ahujarajakku@gmail.com',
    'aileenranjit1999@gmail.com', 'aingelam123@gmail.com', 'aishamuthalib24@gmail.com',
    'aishurups.aiswarya@gmail.com', 'aishwaryadilkush29@gmail.com', 'aiswariyanath2@gmail.com',
    'aiswaryaa296@gmail.com', 'aiswaryab836@gmail.com', 'aiswaryakadavath@gmail.com',
    'aiswaryapavithran47@gmail.com', 'aiswaryapsycho@gmail.com', 'aiswaryaravimay03@gmail.com',
    'ajanyakoyiloth1999@gmail.com', 'ajasrashida3232@gmail.com', 'ajaypayyoli@gmail.com',
    'ajeenajoseph@lissah.com', 'ajumuhd2@gmail.com', 'akhilapv75@gmail.com',
    'akhilnaduvilv007@gmail.com', 'akhitha66@gmail.com', 'akshayamolcp@gmail.com', 'akshayar467@gmail.com',
    'Akshays.sadanandan@gmil.com', 'alanageorge1015@gmail.com', 'aleena.merin3135@gmail.com',
    'aleena170011@gmail.com', 'aleenaannmariya9@gmail.com', 'aleenajames.research@gmail.com',
    'aleenaraju203@gmail.com', 'aleenaralex@gmail.com', 'aleenarg2101@gmail.com', 'aleenavarghese99@gmail.com',
    'aleenavarughese95@gmail.com', 'aleenawilson59@gmail.com', 'aleeshacbasheer@gmail.com',
    'aleetamathew28@gmail.com', 'alenarosealex2001@gmail.com', 'alinaammu0@gmail.com',
    'alinathomas740@gmail.com', 'alishamathew267@gmail.com', 'aliyaasathar@gmail.com',
    'alkakrishnaofficial@gmail.com', 'alkas022004@gmail.com', 'alkereethlal@gmail.com', 'almuneerar@gmail.com',
    'alphinrockz123@gmail.com', 'alveenabiju06@gmail.com', 'alwinpaulalias@gmail.com',
    'amalajomy2000@gmail.com', 'amalasok421@gmail.com', 'amalbnambiar@hotmail.com',
    'amalriyadh31@gmail.com', 'amalsabujoseph127@gmail.com', 'amalshaammalu94@gmail.com',
    'amanikavngl@gmail.com', 'amanyabaiju729@gmail.com', 'ambreenanavas@gmail.com',
    'ameenaameen559@gmail.com', 'ameenambmattathil@gmail.com', 'ameenasfazil2003@gmail.com',
    'ameenasharin2001@gmail.com', 'ameenashraf1272@gmail.com', 'ameenathsahla@gmail.com',
    'ameenmuhammedkc@gmail.com', 'amihameena@gmail.com', 'aminanaseela2000@gmail.com',
    'aminanihala8@gmail.com', 'amithasaji2203@gmail.com', 'amiyamariyam1997@gmail.com',
    'amlaammu24@gmail.com', 'ammucu3902@gmail.com', 'amnafathimacsc@gmail.com',
    'amnah.fathima110@gmail.com', 'amnamna09@gmail.com', 'amnathadathil2000@gmail.com',
    'amnusami206@gmail.com', 'amritabhasi2002@gamil.com', 'amrithakr02@gmail.com',
    'amrithamjacob@gmail.com', 'amrithawork25@gmail.com', 'amruthaettoth@gmail.com',
    'amruthakt2001@gmail.com', 'Amruthapradeep3201@gmail.com', 'amruthavkm2005@gmail.com',
    'anaghaaa26@gmail.com', 'Anaghamanikandanmt@gmail.com', 'anaghamanoharofficial@gmail.com',
    'anaghaprasanth653@gmail.com', 'anaghas089@gmail.com', 'anaghasnair422@gmail.com',
    'anaghasunny13@gmail.com', 'Anaghavperingalath24mscpsy@lissah.com', 'anakhab06@gmail.com',
    'anakhasadan@gmail.com', 'anamikaashokan28@gmail.com', 'anamikacreji@gmail.com',
    'anamikajyothis@gmail.com', 'anamikarajan593@gmail.com', 'ananovajose1217@gmail.com',
    'ananya4756kvk@gmail.com', 'ananyaharidas26@gmail.com', 'ananyap657@gmail.com',
    'anaswaraunnikrishnan69@gmail.com', 'anavadyauday111455@gmail.com', 'ancyperingattu@gmail.com',
    'Andraanandvp20@gmail.com', 'aneenalizbeth@gmail.com', 'aneeshamathewp@gmail.com',
    'aneetaannaroy5001@gmail.com', 'angelancysara@gmail.com', 'angelinbinoy2002@gmail.com',
    'angelmariajose43@gmail.com', 'angelvargheseangel@gmail.com', 'aniesapa5@gmail.com',
    'animina2233@gmail.com', 'anishavibin11@gmail.com', 'anittajaison0@gmail.com',
    'anjali.edu5@gmail.com', 'anjali2002dec@gmail.com', 'Anjalichoyan274@gmail.com',
    'anjalirejila1975@gmail.com', 'anjalivijayakumar201@gmail.com', 'anjanaks7592@gmail.com',
    'anjanaroseponnu@gmail.com', 'anjanasobm@gmail.com', 'anjanawilson3@gmail.com',
    'anjithacnambiar@gmail.com', 'anjithaj2000@gmail.com', 'anjithasanthos@gmail.com',
    'anjubkmpm@gmail.com', 'anjuhibz@gmail.com', 'anjusunil002@gmail.com',
    'anjuthankachan715@gmail.com', 'anjutpwk@gmail.com', 'ankpkl500@gmail.com',
    'annaleena256@gmail.com', 'annamariakuriakose50@gmail.com', 'annecandace04@gmail.com',
    'anniemariampsy.8@gmail.com', 'annm.thomas.05@gmail.com', 'annmaria.arikupurath@gmail.com',
    'annmariaj311@gmail.com', 'annmariyakml@gmail.com', 'annmaryjose895@gmail.com',
    'annmarythomas2225@gmail.com', 'annnithyadawarave@gmail.com', 'annsiby2004@gmail.com',
    'annsusaneldho485@gmail.com', 'annuann2001@gmail.com', 'annuyadav101997@gmail.com',
    'anoopsivadas26@gmail.com', 'anshamic1891@gmail.com', 'anshid101anu@gmail.com',
    'ansiiansila586@gmail.com', 'antriyabais@gmail.com', 'anuanugraha.2102@gmail.com',
    'anugeorge211@gmail.com', 'anugrahamerin@gmail.com', 'anukagopinathp@gmail.com',
    'anup63479@gmail.com', 'anupama54652@gmail.com', 'anupamacn2001@gmail.com',
    'anupamakonnakkal2001@gmail.com', 'anuragvadavathi18@gmail.com', 'anuranjana28@gmail.com',
    'anushaajayakumar4@gmail.com', 'anushanz111@gmail.com', 'anusmithapravi@gmail.com',
    'anusreearumughan@gmail.com', 'anvarsha01010@gmail.com', 'anziaanzi26@gmail.com',
    'anzpersonal425@gmail.com', 'aparna21ae@gmail.com', 'aparnaann97@gmail.com',
    'aparnamohanan13@gmail.com', 'aparnasajikumar22@gmail.com', 'aparnaunni213@gmail.com',
    'apmnambiar@gmail.com', 'aradhanakv770@gmail.com', 'aramanayilneha@gmail', 'arathips94@gmail.com',
    'arathysv427@gmail.com', 'archanakanil26@gmail.com', 'archanakdas2003@gmail.com',
    'archnayadav00@gmail.com', 'ardhrapsugunan@gmail.com', 'areebaschamnad@gmail.com',
    'arjun2004amithu@gmail.com', 'Arjunms8156@gmail.com', 'Arjunpsofficial@gmail.com',
    'aromalmr3@gmail.com', 'arpithatroshan@gmail.com', 'arsha2003avd@gmail.com',
    'arshaddida@gmail.com', 'arshamandody@gmail.com', 'arshasm9785@gmail.com',
    'aruanna2004@gamil.com', 'arundeepkp@gmail.com', 'arundhathias1999@gmail.com',
    'aryadamodaran2001@gmail.com', 'aryagulmohar03@gmail.com', 'aryamohananm@gmail.com',
    'aryapappanamcode@gmail.com', 'aryaprasad0708@gmail.com', 'aryasreek86@gmail.com',
    'asbiya.safar.0@gmail.com', 'Aschaswani@gmail.Com', 'aseenakanoth@gmail.com',
    'Ashermichaelbabu@gmail.com', 'ashfinaap67@gmail.com', 'ashifa983664@gmail.com',
    'Ashifaaachi456@gmail.com', 'ashikashikmohammed.f@gmail.com', 'ashinasolomon10@gmail.com',
    'ashithaashitha005@gmail.com', 'ashithar111@gmail.com', 'ashlinjames18@gmail.com',
    'ashnak2020@gmail.com', 'ashnarahim10@gmail.com', 'ashwinigsatish@gmail.com',
    'ashya.psychologist@gmail.com', 'aslahaazeez777@gmail.com', 'asmashb@gmail.com',
    'asna99ashraf@gmail.com', 'asnaabdulkareem32@gmail.com', 'asnaasees99@gmail.com',
    'asnamk904@gmail.com', 'asnaparveen8589@gmail.com', 'asnathpsy@gmail.com',
    'asnats2021@gmail.com', 'Aswanibiju640@gmail.com', 'aswanimbijuachu@gmail.com',
    'aswathiat123@gmail.com', 'aswathibalan002@gmail.com', 'aswathiek012@gmail.com',
    'aswathikwdr01@gmail.com', 'aswathirajkaravoor@gmail.com', 'aswathy.cvv230119@cvv.ac.in',
    'aswathyammu3902@gmail.com', 'aswathyganga.ganga789@gmail.com', 'aswathym430@gmail.com',
    'aswathymavoor@gmail.com', 'aswathymaya703@gmail.com', 'aswathysuresh703@gmail.com',
    'athak2303@gmail.com', 'athiramohanan2k@gmail.com', 'athirapponnath@gmail.com',
    'athiratbabu1996@gmail.com', 'athiratsathu@gmail.com', 'athulathulkrishna768@gmail.com',
    'athulya.viswam99@gmail.com', 'athulya10000@gmail.com', 'athulyaadish@gmail.com',
    'athulyajayasree245@gmail.com', 'atmanoopcst@gmail.com', 'avanthika680@gmail.com',
    'avkrk192220@gmail.com', 'ayanapr2002@gmail.com', 'ayishafaiha.ke@gmail.com',
    'ayishakeit@gmail.com', 'ayishanada917@gmail.com', 'ayishariza987@gmail.com',
    'ayishashimshna@gmail.com', 'aymansaad5775@gmail.com', 'ayshadiyapk@gmail.com',
    'ayshahiba2626@gmail.com', 'ayshamumthas@gmail.com', 'ayshanourinnoushin@gmail.com',
    'ayshaparveen3581@gmail.com', 'ayshasherin835@gmail.com', 'Ayshfibi2001@gmail.com',
    'ayshu4801@gmail.com', 'azeefaabdhulkareem@gmail.com', 'badirabadarudheen24@gmail.com',
    'balkeeskavarodi@gmail.com', 'barsleeby@gmail.com', 'basimvsalim@gmail.com',
    'bb2217539@gmail.com', 'beeyavathyara1117@gmail.com', 'benignrinsha@gmail.com',
    'betcysimon01@gmail.com', 'bhagupsy@gmail.com', 'bhagyalakshmisreekumar275@gmail.com',
    'bhagyashree3504@gmail.com', 'bhavyasreedileepkumar@gmail.com', 'binshi871@gmail.com',
    'binthazeezhasna@gmail.com', 'binulakshmi000@gmail.com', 'bismi2626@gmail.com',
    'bksjameesha@gmail.com', 'blessybejohn@gmail.com', 'blessypsunny13@gmail.com',
    'care@drramziya.com', 'catherinebabu777@gmail.com', 'catherinempereira17@gmail.com',
    'Cbdivya@gmail.com', 'celinriyagijo@gmail.com', 'cfathimaraniya@gmail.com',
    'chaithanyacmadathil@gmail.com', 'chaithrapp15@gmail.com', 'chandinisanthosh99@gmail.com',
    'chesh.immanuwin@gmail.com', 'chitra.anand.2001@gmail.com', 'cholopsy7@gmail.com',
    'christeenaleslie@gmail.com', 'chulluzz3955@gmail.com', 'cmgayathri08@gmail.com',
    'cmvayathri08@gmail.com', 'connectme.sruthiks@gmail.com', 'cpamritadas1996@gmail.com',
    'cpvafiya@gmail.com', 'cristojoseph2255@gmail.com', 'd0017on@gmail.com',
    'daleena30@gmail.com', 'ddiyaelizabeth@gmail.com', 'deenakvarghese10@gmail.com',
    'Deepafrancism@gmail.com', 'deepthijayank95@gmail.com', 'delnadennis27@gmail.com',
    'devadevazz799@gmail.com', 'devapriyarahulraj@gmail.com', 'devendudinesh1723@gmail.com',
    'devikaedavana@gmail.com', 'devikaka910@gmail.com', 'devikant96@gmail.com',
    'devikasethu87@gmail.com', 'devikasundar8@gmail.com', 'devikasunilm@gmail.com',
    'dhanuemathew@gmail.com', 'dharsanamm70@gmail.com', 'dhilshisaramathai@gmail.com',
    'Dhiyansmom@gmail.com', 'dhrisyamariyam@gmail.com', 'didamhd8@gmail.com',
    'dijaahammed2010@gmail.com', 'dilfanaa999@gmail.com', 'dilnaemt@gmail.com',
    'dilnaluckman@gmail.com', 'dilnaprasad15@gmail.com', 'dilnasebastian2003@gmail.com',
    'dilnashefin21@gmail.com', 'dilrosebanu77@gmail.com', 'dilsha.sathaja@gmail.com',
    'dilshadaraihan@gmail.com', 'diluabid2001@gmail.com', 'divyagpsy@gmail.com',
    'divyagpsy16@gmail.com', 'divyamangulam547113@gmail.com', 'divyarajeevsr@gmail.com',
    'divyasree2112003@gmail.com', 'divyasusan2003@gmail.com', 'diya10480@gmail.com',
    'diyaakbarkp2@gmail.com', 'diyaannjose04@gmail.com', 'diyabsr1@gmail.com',
    'diyadevadasmorikkara@gmail.com', 'diyamurali.dm@gmail.com', 'diyaneelimr@gmail.com',
    'diyasana9744927272@gmail.com', 'diyasharish2@gmail.com', 'dmariamjimmy@gmail.com',
    'donabaiju.mar@gmail.com', 'donarosedavis@gmail.com', 'donayesudas@gmail.com',
    'dramrutakr@gmail.com', 'drishyaprasannaraj29@gmail.com', 'drisyaspm@gmail.com',
    'edwinpj2001@gmail.com', 'eleshmol2002@gmail.com', 'elizabethkeziya@gmail.com',
    'elizabethmathew285@gmail.com', 'elizabethsaraj@gmail.com', 'Emiltresa03@gmail.com',
    'emmanustanley812@gmail.com', 'eprisba@gmail.com', 'epunais111@gmail.com',
    'er.ahmedsahal@gmail.com', 'erjimson@gmail.com', 'eshafathima2006@gmail.com',
    'euhanoed@gmail.com', 'evadk2004@gmail.com', 'exams2025amna@gmail.com',
    'excelrafna@gmail.com', 'eyaelysian@gmail.com', 'faaiirshad@gmqil.com',
    'faathimaaah61@gmail.com', 'fabeehasameer@gmail.com', 'fadhilpulath@gmail.com',
    'fadilmustafacv02@gmail.com', 'fadiyash21@gmail.com', 'fahidariyas@gmail.com',
    'faisytky@gmail.com', 'fajnarahman@gmail.com', 'fami22499@gmail.com',
    'faminamundeth@gmail.com', 'farhafaz87@gmail.com', 'farhanashameer56@gmail', 'farhanashameer56@gmail.com',
    'farsanaam03@gmail.com', 'farsanamol97@gmail.com', 'farsanatasnim89@gmail.com',
    'farshaam16@gmail.com', 'Farwanashabukker@gmail.com', 'farzzzmrr57@gmail.com',
    'faseeha1702@gmail.com', 'faseelathoombat@gmail.com', 'fasnaabeegum13@gmail.com',
    'fasnashaji090@gmail.com', 'Fathifathima0224@gmail.com', 'fathii.raz88@gmail.com',
    'fathima.nazrinpk003@gmail.com', 'fathima.nediyavilayil@gmail.com', 'fathima1812810@gmail.com',
    'fathimaaik@gmail.com', 'fathimaaslahan@gmail.com', 'fathimabeegam2002@gail.com',
    'fathimabeegam2002@gmail.com', 'fathimabeegam79@gmail.com', 'fathimabeeviclt@gmail.com',
    'fathimackfathi@gmail.com', 'fathimadavood619@gmail.com', 'fathimafuhada3@gmail.com',
    'Fathimahanaap@gmail.com', 'fathimahibaah114@gmail.com', 'Fathimahibamv@gmail.com',
    'fathimajafni3463@gmail.com', 'fathimaminu30@gmail.com', 'fathimamony123@gmail.com',
    'fathimamp531@gmail.com', 'fathimamusfina462946@gmail.com', 'fathimanabav@gmail.com',
    'fathimanahla292@gmail.com', 'fathimanajiyanazir@gmail.com', 'fathimanaseehacm@gmail.com',
    'fathimanaslack@gmail.com', 'fathimanoora294@gmail.com', 'fathimanuhavs@gmail.com',
    'fathimaranna81@gmail.com', 'fathimarosly8111@gmail.com', 'fathimas52345@gmail.com',
    'fathimasana9082@gmail.Com', 'fathimashahma171@gmail.com', 'fathimasherinma786@gmail.com',
    'fathimasidhyc327@gmail.com', 'fathimathashreefakp2002@gmail.com', 'fathimathhibakp817@gmail.com',
    'fathimathulmeha138@gmail.com', 'fathimathusahala2019@gmail.com', 'fathimathusahda276@gmail.com',
    'fathimayousuf101@gmail.com', 'fathinizar5000@gmail.com', 'fazconnect@gmail.com',
    'fazilabdu@gmail.com', 'fazlafaisal2006@gmail.com', 'faznabasheer.8281@gmail.com',
    'Fazzfarooq13@gmail.com', 'feabaphilip1998@gmail.com', 'femijisana172@gmail.com', 'femimuzaffar@gmail.com'
);

-- Check number of rows affected
SELECT ROW_COUNT() as rows_updated;

-- Commit the transaction
COMMIT;

-- Verification Query - Check a few updated names
SELECT email, name FROM users 
WHERE email IN ('13farha@gmail.com', 'ablajomy2000@gmail.com', 'care@drramziya.com')
LIMIT 10;
