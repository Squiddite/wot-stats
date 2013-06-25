create table if not exists wotstats (
   id             int not null auto_increment primary key,
   playerid       int not null,
   cachedate      int,
   statsdate      int,
   battles        int,
   victories      int,
   detections     int,
   defense        int,
   kills          int,
   damage         int,
   checkpoint     bit not null default 0
);

create table if not exists wotstats_tanks (
   statid         int not null,
   tankname       varchar(100) not null,
   tier           int,
   battles        int,
   victories      int
);
alter table wotstats_tanks add foreign key (statid) references wotstats (id) on delete cascade;
